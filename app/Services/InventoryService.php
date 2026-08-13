<?php

namespace App\Services;

use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function availability(InventoryItem $item, CarbonInterface $from, CarbonInterface $to): array
    {
        $allocated = (float) DB::table('allocations')
            ->join('request_items', 'request_items.id', '=', 'allocations.request_item_id')
            ->where('request_items.inventory_item_id', $item->id)
            ->whereIn('allocations.status', ['ACTIVE', 'PARTIALLY_RELEASED'])
            ->where('allocations.period_start', '<=', $to)
            ->where('allocations.period_end', '>=', $from)
            ->selectRaw('COALESCE(SUM(allocated_quantity - released_quantity - restored_quantity), 0) AS quantity')
            ->value('quantity');

        $borrowed = (float) DB::table('custody_lines')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->where('request_items.inventory_item_id', $item->id)
            ->whereIn('custody_transactions.status', ['ACTIVE', 'PARTIALLY_RETURNED', 'OVERDUE', 'EARLY_RETURN', 'INCIDENT_OPEN'])
            ->where('custody_transactions.due_at', '>=', $from)
            ->whereRaw('COALESCE(custody_transactions.released_at, custody_transactions.scheduled_release_at) <= ?', [$to])
            ->selectRaw('COALESCE(SUM(actual_released_quantity - returned_quantity), 0) AS quantity')
            ->value('quantity');

        $laundry = (float) DB::table('laundry_records')
            ->join('return_lines', 'return_lines.id', '=', 'laundry_records.return_line_id')
            ->join('custody_lines', 'custody_lines.id', '=', 'return_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->where('request_items.inventory_item_id', $item->id)
            ->whereNotIn('laundry_records.status', ['VERIFIED', 'CANCELLED'])
            ->sum('return_lines.quantity_received');

        $incident = (float) DB::table('incident_lines')
            ->join('incidents', 'incidents.id', '=', 'incident_lines.incident_id')
            ->join('custody_lines', 'custody_lines.id', '=', 'incident_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->where('request_items.inventory_item_id', $item->id)
            ->sum('incident_lines.quantity');

        $incidentStates = DB::table('incident_lines')
            ->join('custody_lines', 'custody_lines.id', '=', 'incident_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->where('request_items.inventory_item_id', $item->id)
            ->selectRaw('incident_lines.disposition_state, COALESCE(SUM(incident_lines.quantity), 0) AS quantity')
            ->groupBy('incident_lines.disposition_state')
            ->pluck('quantity', 'disposition_state');

        $total = (float) $item->total_quantity;
        $serviceableTotal = $item->active && $item->condition_code === 'SERVICEABLE' ? $total : 0.0;

        return [
            'total' => $total,
            'allocated' => $allocated,
            'borrowed' => $borrowed,
            'laundry' => $laundry,
            'incident' => $incident,
            'damaged_maintenance' => (float) ($incidentStates['DAMAGED_MAINTENANCE'] ?? 0) + ($item->condition_code === 'DAMAGED_MAINTENANCE' ? $total : 0),
            'lost' => (float) ($incidentStates['LOST'] ?? 0),
            'stolen' => (float) ($incidentStates['STOLEN'] ?? 0),
            'destroyed' => (float) ($incidentStates['DESTROYED'] ?? 0),
            'condemned' => $item->condition_code === 'CONDEMNED' ? $total : 0.0,
            'available' => max(0, $serviceableTotal - $allocated - $borrowed - $laundry - $incident),
        ];
    }

    /** @return list<Allocation> */
    public function allocate(RequestVersion $version): array
    {
        return DB::transaction(function () use ($version): array {
            $version->loadMissing('items.inventoryItem');
            $allocations = [];
            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'actor_user_id' => auth()->id(),
                'transaction_type' => 'FINAL_APPROVAL_ALLOCATION',
                'source_type' => RequestVersion::class,
                'source_id' => $version->id,
                'reason' => 'Atomic allocation after final VPAF approval.',
                'correlation_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($version->items as $requestItem) {
                $item = InventoryItem::query()->lockForUpdate()->findOrFail($requestItem->inventory_item_id);
                $balance = $this->availability($item, $version->needed_from, $version->return_due_at);
                $requested = (float) $requestItem->requested_quantity;

                if (! $item->borrowable || $requested <= 0 || $balance['available'] < $requested) {
                    throw ValidationException::withMessages([
                        'inventory' => "{$item->unique_description} has only {$balance['available']} available for the requested period. The request was returned to SPMU without allocation.",
                    ]);
                }

                $requestItem->update(['approved_quantity' => $requestItem->requested_quantity]);
                $allocation = Allocation::query()->create([
                    'request_item_id' => $requestItem->id,
                    'period_start' => $version->needed_from,
                    'period_end' => $version->return_due_at,
                    'allocated_quantity' => $requestItem->requested_quantity,
                    'released_quantity' => 0,
                    'restored_quantity' => 0,
                    'status' => 'ACTIVE',
                    'allocated_at' => now(),
                ]);
                $allocations[] = $allocation;

                DB::table('inventory_transaction_lines')->insert([
                    'inventory_transaction_id' => $transactionId,
                    'inventory_item_id' => $item->id,
                    'from_state' => 'AVAILABLE',
                    'to_state' => 'ALLOCATED',
                    'quantity' => $requested,
                    'effective_from' => $version->needed_from,
                    'effective_to' => $version->return_due_at,
                    'before_quantity' => $balance['available'],
                    'after_quantity' => $balance['available'] - $requested,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $allocations;
        }, 3);
    }

    public function restore(BorrowingRequest $request, string $status, string $reason): void
    {
        DB::transaction(function () use ($request, $status, $reason): void {
            $request->loadMissing('currentVersion.items.allocation');
            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'actor_user_id' => auth()->id(),
                'transaction_type' => 'ALLOCATION_RESTORATION',
                'source_type' => BorrowingRequest::class,
                'source_id' => $request->id,
                'reason' => $reason,
                'correlation_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($request->currentVersion->items as $requestItem) {
                $allocation = $requestItem->allocation;
                if (! $allocation || ! in_array($allocation->status, ['ACTIVE', 'PARTIALLY_RELEASED'], true)) {
                    continue;
                }
                $remaining = max(0, (float) $allocation->allocated_quantity - (float) $allocation->released_quantity - (float) $allocation->restored_quantity);
                if ($remaining <= 0) {
                    continue;
                }
                $allocation->update([
                    'restored_quantity' => (float) $allocation->restored_quantity + $remaining,
                    'status' => $status,
                ]);
                DB::table('inventory_transaction_lines')->insert([
                    'inventory_transaction_id' => $transactionId,
                    'inventory_item_id' => $requestItem->inventory_item_id,
                    'from_state' => 'ALLOCATED',
                    'to_state' => 'AVAILABLE',
                    'quantity' => $remaining,
                    'effective_from' => $allocation->period_start,
                    'effective_to' => $allocation->period_end,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
    }
}
