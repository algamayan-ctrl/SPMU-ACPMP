<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\UnitOfMeasure;
use App\Services\AuditService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryService $inventory): View
    {
        $from = Carbon::parse(
            $request->input('from', now()->addDay()->toDateString())
        )->startOfDay();

        $to = Carbon::parse(
            $request->input('to', now()->addDays(7)->toDateString())
        )->endOfDay();

        if ($to->toDateString() < $from->toDateString()) {
            $to = $from->copy()->endOfDay();
        }

        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        $borrowerMode = $workspace === 'BORROWER';

        $itemsQuery = InventoryItem::query()
            ->with(['category', 'unit'])
            ->where('active', true)
            ->orderBy('unique_description');

        /*
         * Borrowers see only items that are actually borrowable and in the
         * serviceable/good condition pool. Internal conditions remain SPMU-only.
         */
        if ($borrowerMode) {
            $itemsQuery
                ->where('borrowable', true)
                ->where('condition_code', 'SERVICEABLE');
        }

        $items = $itemsQuery->get();
        $balances = $items->mapWithKeys(
            fn (InventoryItem $item) => [
                $item->id => $inventory->availability($item, $from, $to),
            ]
        );

        return view('inventory.index', compact(
            'items',
            'balances',
            'from',
            'to',
            'borrowerMode',
            'search',
            'categoryId',
            'categories',
            'workspace'
        ));
    }

    public function show(
        Request $request,
        InventoryItem $inventory,
        InventoryService $service
    ): View {
        $workspace = strtoupper(
            (string) $request->user()->primaryWorkspace()
        );

        if (! in_array($workspace, ['BORROWER', 'SPMU'], true)) {
            abort(403);
        }

        if (! $inventory->active) {
            abort(404);
        }

        $inventory->loadMissing(['category', 'unit']);

        $balance = $service->availability(
            $inventory,
            now(),
            now()->addSecond()
        );

        /*
         * Borrowers may open details only for inventory that is actually
         * visible in the Borrower Inventory list.
         */
        if ($workspace === 'BORROWER') {
            $available = (float) (
                $balance['borrower_available']
                ?? $balance['available']
                ?? 0
            );

            if (
                ! $inventory->borrowable
                || $inventory->condition_code !== 'SERVICEABLE'
                || $available <= 0
            ) {
                abort(404);
            }
        }

        return view('inventory.show', [
            'item' => $inventory,
            'balance' => $balance,
            'isBorrower' => $workspace === 'BORROWER',
            'isSpmu' => $workspace === 'SPMU',
        ]);
    }
    public function create(): View
    {
        return view('inventory.form', [
            'item' => new InventoryItem,
            'categories' => InventoryCategory::query()->where('active', true)->get(),
            'units' => UnitOfMeasure::query()->where('active', true)->get(),
        ]);
    }

    /**
     * Date-only availability API. Any time portion sent by an older UI is
     * intentionally discarded.
     */
    public function availabilityData(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        if ($to->toDateString() < $from->toDateString()) {
            throw ValidationException::withMessages([
                'to' => 'Return Date cannot be earlier than the Schedule Date.',
            ]);
        }

        $items = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('condition_code', 'SERVICEABLE')
            ->get();

        return response()->json(
            $items->mapWithKeys(
                fn (InventoryItem $item) => [
                    $item->id => $inventory->availability($item, $from, $to),
                ]
            )
        );
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $item = InventoryItem::query()->create($data);

        $audit->record(
            'INVENTORY_ITEM_CREATED',
            $item,
            reason: $request->input('change_reason'),
            after: $item->toArray()
        );

        return redirect()->route('inventory.index')->with('status', 'Inventory item created.');
    }

    public function edit(InventoryItem $inventory): View
    {
        return view('inventory.form', [
            'item' => $inventory,
            'categories' => InventoryCategory::query()->where('active', true)->get(),
            'units' => UnitOfMeasure::query()->where('active', true)->get(),
        ]);
    }

    public function update(
        Request $request,
        InventoryItem $inventory,
        InventoryService $service,
        AuditService $audit
    ): RedirectResponse {
        $data = $this->validated($request, $inventory);

        $balance = $service->availability(
            $inventory,
            now()->subYears(10)->startOfDay(),
            now()->addYears(10)->endOfDay()
        );

        $committed = $balance['allocated']
            + $balance['borrowed']
            + $balance['laundry']
            + $balance['incident'];

        if ((float) $data['total_quantity'] < $committed) {
            throw ValidationException::withMessages([
                'total_quantity' => "Total quantity cannot be reduced below the active commitment of {$committed}.",
            ]);
        }

        $before = $inventory->toArray();
        $inventory->update($data);

        $audit->record(
            'INVENTORY_ITEM_UPDATED',
            $inventory,
            reason: $request->input('change_reason'),
            before: $before,
            after: $inventory->fresh()->toArray()
        );

        return redirect()->route('inventory.index')->with('status', 'Inventory item updated with an audit record.');
    }

    private function validated(Request $request, ?InventoryItem $item = null): array
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:inventory_categories,id'],
            'unit_id' => ['required', 'exists:units_of_measure,id'],
            'unique_description' => [
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_items')
                    ->where(fn ($query) => $query->where('category_id', $request->integer('category_id')))
                    ->ignore($item?->id),
            ],
            'specification' => ['nullable', 'string'],
            'total_quantity' => ['required', 'numeric', 'min:0'],
            'condition_code' => ['required', Rule::in(['SERVICEABLE', 'DAMAGED_MAINTENANCE', 'CONDEMNED'])],
            'change_reason' => ['required', 'string', 'max:1000'],
        ]);

        $data['borrowable'] = $request->boolean('borrowable');
        $data['off_campus_allowed'] = $request->boolean('off_campus_allowed');
        $data['laundry_required'] = $request->boolean('laundry_required');
        $data['provisional'] = $request->boolean('provisional');
        $data['active'] = $request->boolean('active', true);
        unset($data['change_reason']);

        if ($data['off_campus_allowed'] && strcasecmp($data['unique_description'], 'Barricade') !== 0) {
            throw ValidationException::withMessages([
                'off_campus_allowed' => 'Current policy permits off-campus use only for Barricade.',
            ]);
        }

        return $data;
    }
}
