<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\OverdueCase;
use App\Models\Penalty;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, InventoryService $inventory): View
    {
        $from = Carbon::parse($request->input('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        $items = InventoryItem::with(['category', 'unit'])->where('active', true)->get();
        $balances = $items->mapWithKeys(fn ($item) => [$item->id => $inventory->availability($item, now()->subYears(10), now()->addYears(10))]);
        $requestStatuses = BorrowingRequest::query()->whereBetween('created_at', [$from, $to])->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $custodyStatuses = CustodyTransaction::query()->whereBetween('created_at', [$from, $to])->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $topItems = InventoryItem::query()
            ->leftJoin('request_items', 'request_items.inventory_item_id', '=', 'inventory_items.id')
            ->leftJoin('custody_lines', 'custody_lines.request_item_id', '=', 'request_items.id')
            ->selectRaw('inventory_items.unique_description, COALESCE(SUM(custody_lines.actual_released_quantity), 0) AS used_quantity')
            ->groupBy('inventory_items.id', 'inventory_items.unique_description')
            ->orderByDesc('used_quantity')->limit(10)->get();

        return view('reports.index', [
            'items' => $items,
            'balances' => $balances,
            'requestStatuses' => $requestStatuses,
            'custodyStatuses' => $custodyStatuses,
            'topItems' => $topItems,
            'failedNotifications' => NotificationDelivery::where('delivery_status', 'FAILED')->count(),
            'auditCount' => AuditEvent::count(),
            'from' => $from,
            'to' => $to,
            'overdueCount' => OverdueCase::whereBetween('created_at', [$from, $to])->count(),
            'repeatOffenders' => OverdueCase::whereBetween('created_at', [$from, $to])->where('offense_level', '>', 1)->distinct()->count('borrower_user_id'),
            'penaltyTotal' => Penalty::whereBetween('assessed_at', [$from, $to])->sum('amount'),
            'dueSoonCount' => CustodyTransaction::whereIn('status', ['ACTIVE', 'PARTIALLY_RETURNED'])->whereBetween('due_at', [now(), now()->addHours(24)])->count(),
            'returnCompliance' => $this->returnCompliance($from, $to),
            'averageApprovalSeconds' => (int) DB::table('kpi_observations')->where('process_code', 'DIGITAL_APPROVAL_CYCLE')->whereBetween('completed_at', [$from, $to])->avg('duration_seconds'),
        ]);
    }

    public function export(Request $request, string $type, InventoryService $inventory): StreamedResponse
    {
        abort_unless(in_array($type, ['inventory', 'borrowing', 'utilization', 'overdue', 'penalty', 'compliance', 'notification', 'audit'], true), 404);
        if (in_array($type, ['notification', 'audit'], true)) {
            abort_unless($request->user()->hasRole(UserRole::Spmu) || $request->user()->hasRole(UserRole::Ictu), 403);
        }
        $from = Carbon::parse($request->input('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        return response()->streamDownload(function () use ($type, $from, $to, $inventory): void {
            $output = fopen('php://output', 'w');
            if ($type === 'inventory') {
                fputcsv($output, ['Description', 'Category', 'Unit', 'Total', 'Available', 'Allocated', 'Borrowed', 'Laundry', 'Damaged/Maintenance', 'Lost', 'Stolen', 'Destroyed', 'Condemned']);
                InventoryItem::with(['category', 'unit'])->where('active', true)->orderBy('unique_description')->each(function ($item) use ($output, $inventory): void {
                    $balance = $inventory->availability($item, now()->subYears(10), now()->addYears(10));
                    fputcsv($output, [$item->unique_description, $item->category->category_name, $item->unit->unit_name, $balance['total'], $balance['available'], $balance['allocated'], $balance['borrowed'], $balance['laundry'], $balance['damaged_maintenance'], $balance['lost'], $balance['stolen'], $balance['destroyed'], $balance['condemned']]);
                });
            } elseif ($type === 'borrowing') {
                fputcsv($output, ['Request No.', 'Borrower', 'Status', 'Created', 'Final Approved']);
                BorrowingRequest::with('borrower')->whereBetween('created_at', [$from, $to])->each(fn ($row) => fputcsv($output, [$row->request_no, $row->borrower->full_name, $row->status->value, $row->created_at, $row->final_approved_at]));
            } elseif ($type === 'utilization') {
                fputcsv($output, ['Description', 'Released Quantity']);
                InventoryItem::query()
                    ->leftJoin('request_items', 'request_items.inventory_item_id', '=', 'inventory_items.id')
                    ->leftJoin('custody_lines', 'custody_lines.request_item_id', '=', 'request_items.id')
                    ->leftJoin('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
                    ->where(function ($query) use ($from, $to): void {
                        $query->whereNull('custody_transactions.released_at')->orWhereBetween('custody_transactions.released_at', [$from, $to]);
                    })
                    ->selectRaw('inventory_items.unique_description, COALESCE(SUM(custody_lines.actual_released_quantity), 0) AS used_quantity')
                    ->groupBy('inventory_items.id', 'inventory_items.unique_description')
                    ->orderByDesc('used_quantity')->each(fn ($row) => fputcsv($output, [$row->unique_description, $row->used_quantity]));
            } elseif ($type === 'overdue') {
                fputcsv($output, ['Custody No.', 'Borrower', 'Offense', 'Sanction', 'Rate', 'Accrued', 'Status']);
                OverdueCase::with(['custody', 'borrower'])->whereBetween('created_at', [$from, $to])->each(fn ($row) => fputcsv($output, [$row->custody->custody_no, $row->borrower->full_name, $row->offense_level, $row->sanction_type, $row->rate_snapshot, $row->accrued_amount, $row->status]));
            } elseif ($type === 'penalty') {
                fputcsv($output, ['Borrower ID', 'Type', 'Offense', 'Rate', 'Amount', 'Status', 'Assessed']);
                Penalty::whereBetween('assessed_at', [$from, $to])->each(fn ($row) => fputcsv($output, [$row->borrower_user_id, $row->penalty_type, $row->offense_level, $row->rate_snapshot, $row->amount, $row->status, $row->assessed_at]));
            } elseif ($type === 'notification') {
                fputcsv($output, ['Time', 'Event', 'Channel', 'Recipient User ID', 'Address', 'Provider', 'Status', 'Response']);
                NotificationDelivery::with('event')->whereBetween('attempted_at', [$from, $to])->each(fn ($row) => fputcsv($output, [$row->attempted_at, $row->event->event_code, $row->channel, $row->recipient_user_id, $row->address_snapshot, $row->provider, $row->delivery_status, $row->provider_response]));
            } elseif ($type === 'audit') {
                fputcsv($output, ['Time', 'Actor User ID', 'Action', 'Record Type', 'Record ID', 'Reason', 'Origin IP']);
                AuditEvent::whereBetween('occurred_at', [$from, $to])->each(fn ($row) => fputcsv($output, [$row->occurred_at, $row->actor_user_id, $row->action_code, $row->record_type, $row->record_id, $row->reason, $row->origin_ip]));
            } else {
                fputcsv($output, ['Period From', 'Period To', 'Released Custodies', 'Closed Custodies', 'Return Compliance Percent']);
                $compliance = $this->returnCompliance($from, $to);
                fputcsv($output, [$from->toDateString(), $to->toDateString(), $compliance['released'], $compliance['closed'], $compliance['percentage']]);
            }
            fclose($output);
        }, "spmu-{$type}-report-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function audit(): View
    {
        return view('reports.audit', ['events' => AuditEvent::with('actor')->latest('occurred_at')->limit(500)->get()]);
    }

    public function notifications(): View
    {
        return view('reports.notifications', ['deliveries' => NotificationDelivery::with('event')->latest('created_at')->limit(500)->get()]);
    }

    /** @return array{released: int, closed: int, percentage: float} */
    private function returnCompliance(Carbon $from, Carbon $to): array
    {
        $released = CustodyTransaction::whereBetween('released_at', [$from, $to])->count();
        $closed = CustodyTransaction::whereBetween('released_at', [$from, $to])->where('status', 'CLOSED')->count();

        return ['released' => $released, 'closed' => $closed, 'percentage' => $released ? round($closed / $released * 100, 2) : 0];
    }
}
