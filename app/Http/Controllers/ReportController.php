<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\BillingStatement;
use App\Models\BorrowerViolation;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\IncidentLine;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\OverdueCase;
use App\Models\Penalty;
use App\Models\Sanction;
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
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );
        $from = Carbon::parse($request->input('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $items = InventoryItem::query()
            ->with(['category', 'unit'])
            ->where('active', true)
            ->get();

        $balances = $items->mapWithKeys(
            fn ($item) => [
                $item->id => $inventory->availability(
                    $item,
                    now()->subYears(10)->startOfDay(),
                    now()->addYears(10)->endOfDay()
                ),
            ]
        );

        $requestStatuses = BorrowingRequest::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $custodyStatuses = CustodyTransaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $topItems = InventoryItem::query()
            ->leftJoin('request_items', 'request_items.inventory_item_id', '=', 'inventory_items.id')
            ->leftJoin('custody_lines', 'custody_lines.request_item_id', '=', 'request_items.id')
            ->leftJoin('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->where(function ($query) use ($from, $to): void {
                $query->whereNull('custody_transactions.released_at')
                    ->orWhereBetween('custody_transactions.released_at', [$from, $to]);
            })
            ->selectRaw('inventory_items.unique_description, COALESCE(SUM(custody_lines.actual_released_quantity), 0) AS used_quantity')
            ->groupBy('inventory_items.id', 'inventory_items.unique_description')
            ->orderByDesc('used_quantity')
            ->limit(10)
            ->get();

        $mostFrequentBorrowers = BorrowingRequest::query()
            ->join('users', 'users.id', '=', 'borrowing_requests.borrower_user_id')
            ->whereBetween('borrowing_requests.created_at', [$from, $to])
            ->selectRaw('users.id, users.full_name, COUNT(borrowing_requests.id) AS borrowing_count')
            ->groupBy('users.id', 'users.full_name')
            ->orderByDesc('borrowing_count')
            ->limit(10)
            ->get();

        $borrowersWithMostLateReturns = OverdueCase::query()
            ->join('users', 'users.id', '=', 'overdue_cases.borrower_user_id')
            ->whereBetween('overdue_cases.created_at', [$from, $to])
            ->selectRaw('users.id, users.full_name, COUNT(overdue_cases.id) AS late_return_count')
            ->groupBy('users.id', 'users.full_name')
            ->orderByDesc('late_return_count')
            ->limit(10)
            ->get();

        $borrowersWithMostViolations = BorrowerViolation::query()
            ->join('users', 'users.id', '=', 'borrower_violations.borrower_user_id')
            ->where('borrower_violations.status', 'CONFIRMED')
            ->whereBetween('borrower_violations.detected_at', [$from, $to])
            ->selectRaw('users.id, users.full_name, COUNT(borrower_violations.id) AS violation_count')
            ->groupBy('users.id', 'users.full_name')
            ->orderByDesc('violation_count')
            ->limit(10)
            ->get();

        $assetConditionTrends = IncidentLine::query()
            ->join('incidents', 'incidents.id', '=', 'incident_lines.incident_id')
            ->join('custody_lines', 'custody_lines.id', '=', 'incident_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'request_items.inventory_item_id')
            ->whereBetween('incidents.reported_at', [$from, $to])
            ->selectRaw('inventory_items.unique_description, incident_lines.observed_condition, SUM(incident_lines.quantity) AS affected_quantity')
            ->groupBy('inventory_items.id', 'inventory_items.unique_description', 'incident_lines.observed_condition')
            ->orderByDesc('affected_quantity')
            ->limit(20)
            ->get();

        $approvalDurations = DB::table('approval_steps')
            ->where('stage_code', 'SPMU')
            ->whereNotNull('received_at')
            ->whereNotNull('decided_at')
            ->whereBetween('decided_at', [$from, $to])
            ->get(['received_at', 'decided_at'])
            ->map(function ($step): int {
                $receivedAt = Carbon::parse($step->received_at);
                $decidedAt = Carbon::parse($step->decided_at);

                return max(0, $receivedAt->diffInSeconds($decidedAt, false));
            });

        $averageApprovalSeconds = $approvalDurations->isEmpty()
            ? 0
            : (int) round($approvalDurations->avg());

        return view('reports.index', [
            'items' => $items,
            'balances' => $balances,
            'requestStatuses' => $requestStatuses,
            'custodyStatuses' => $custodyStatuses,
            'topItems' => $topItems,
            'mostFrequentBorrowers' => $mostFrequentBorrowers,
            'borrowersWithMostLateReturns' => $borrowersWithMostLateReturns,
            'borrowersWithMostViolations' => $borrowersWithMostViolations,
            'assetConditionTrends' => $assetConditionTrends,
            'failedNotifications' => NotificationDelivery::query()->where('delivery_status', 'FAILED')->count(),
            'auditCount' => AuditEvent::query()->count(),
            'from' => $from,
            'to' => $to,
            'overdueCount' => OverdueCase::query()->whereBetween('created_at', [$from, $to])->count(),
            'repeatOffenders' => BorrowerViolation::query()
                ->where('status', 'CONFIRMED')
                ->whereBetween('detected_at', [$from, $to])
                ->select('borrower_user_id')
                ->groupBy('borrower_user_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count(),
            'penaltyTotal' => Penalty::query()->whereBetween('assessed_at', [$from, $to])->sum('amount'),
            'billingTotal' => BillingStatement::query()->whereBetween('issued_at', [$from, $to])->sum('total_amount'),
            'sanctionCount' => Sanction::query()->whereBetween('confirmed_at', [$from, $to])->count(),
            'dueSoonCount' => CustodyTransaction::query()
                ->whereIn('status', ['ACTIVE', 'RETURN_PROCESSING'])
                ->whereDate('due_at', '>=', now()->toDateString())
                ->whereDate('due_at', '<=', now()->addDay()->toDateString())
                ->count(),
            'returnCompliance' => $this->returnCompliance($from, $to),
            'averageApprovalSeconds' => $averageApprovalSeconds,
        ]);
    }

    public function export(
        Request $request,
        string $type,
        InventoryService $inventory
    ): StreamedResponse {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );

        abort_unless(
            in_array(
                $type,
                [
                    'inventory',
                    'borrowing',
                    'utilization',
                    'overdue',
                    'penalty',
                    'billing',
                    'sanction',
                    'compliance',
                    'notification',
                    'audit',
                ],
                true
            ),
            404
        );

        if (in_array($type, ['notification', 'audit'], true)) {
            abort_unless(
                $request->user()->hasRole(UserRole::Spmu)
                || $request->user()->hasRole(UserRole::Ictu),
                403
            );
        }

        $from = Carbon::parse($request->input('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        return response()->streamDownload(function () use ($type, $from, $to, $inventory): void {
            $output = fopen('php://output', 'w');

            if ($type === 'inventory') {
                fputcsv($output, ['Description', 'Category', 'Unit', 'Total', 'Available', 'Reserved', 'Issued/On Custody', 'Laundry', 'Damaged/Maintenance', 'Lost', 'Stolen', 'Destroyed', 'Condemned']);
                InventoryItem::query()->with(['category', 'unit'])->where('active', true)->orderBy('unique_description')->each(function ($item) use ($output, $inventory): void {
                    $balance = $inventory->availability($item, now()->subYears(10)->startOfDay(), now()->addYears(10)->endOfDay());
                    fputcsv($output, [
                        $item->unique_description,
                        $item->category->category_name,
                        $item->unit->unit_name,
                        $balance['total'],
                        $balance['available'],
                        $balance['allocated'],
                        $balance['borrowed'],
                        $balance['laundry'],
                        $balance['damaged_maintenance'],
                        $balance['lost'],
                        $balance['stolen'],
                        $balance['destroyed'],
                        $balance['condemned'],
                    ]);
                });
            } elseif ($type === 'borrowing') {
                fputcsv($output, ['Request No.', 'Borrower', 'Status', 'Schedule Date', 'Return Date', 'Created', 'SPMU Approved']);
                BorrowingRequest::query()->with(['borrower', 'currentVersion'])->whereBetween('created_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->request_no,
                    $row->borrower->full_name,
                    $row->status->value,
                    $row->currentVersion?->schedule_date?->toDateString() ?? $row->currentVersion?->needed_from?->toDateString(),
                    $row->currentVersion?->return_date?->toDateString() ?? $row->currentVersion?->return_due_at?->toDateString(),
                    $row->created_at,
                    $row->final_approved_at,
                ]));
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
                    ->orderByDesc('used_quantity')
                    ->each(fn ($row) => fputcsv($output, [$row->unique_description, $row->used_quantity]));
            } elseif ($type === 'overdue') {
                fputcsv($output, ['Custody No.', 'Borrower', 'Expected Return Date', 'Late Fee Rate', 'Accrued Amount', 'Status']);
                OverdueCase::query()->with(['custody', 'borrower'])->whereBetween('created_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->custody->custody_no,
                    $row->borrower->full_name,
                    $row->custody->due_at?->toDateString(),
                    $row->rate_snapshot,
                    $row->accrued_amount,
                    $row->status,
                ]));
            } elseif ($type === 'penalty') {
                fputcsv($output, ['Borrower ID', 'Financial Charge Type', 'Rate', 'Amount', 'Status', 'Assessed']);
                Penalty::query()->whereBetween('assessed_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->borrower_user_id,
                    $row->penalty_type,
                    $row->rate_snapshot,
                    $row->amount,
                    $row->status,
                    $row->assessed_at,
                ]));
            } elseif ($type === 'billing') {
                fputcsv($output, ['Billing No.', 'Borrower ID', 'Amount', 'Status', 'Issued']);
                BillingStatement::query()->whereBetween('issued_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->billing_no,
                    $row->borrower_user_id,
                    $row->total_amount,
                    $row->status,
                    $row->issued_at,
                ]));
            } elseif ($type === 'sanction') {
                fputcsv($output, ['Borrower ID', 'Offense No.', 'Sanction', 'Academic Period ID', 'Status', 'Confirmed']);
                Sanction::query()->whereBetween('confirmed_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->borrower_user_id,
                    $row->offense_no,
                    $row->sanction_label,
                    $row->academic_period_id,
                    $row->status,
                    $row->confirmed_at,
                ]));
            } elseif ($type === 'notification') {
                fputcsv($output, ['Time', 'Event', 'Channel', 'Recipient User ID', 'Address', 'Provider', 'Status', 'Response']);
                NotificationDelivery::query()->with('event')->whereBetween('attempted_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->attempted_at,
                    $row->event->event_code,
                    $row->channel,
                    $row->recipient_user_id,
                    $row->address_snapshot,
                    $row->provider,
                    $row->delivery_status,
                    $row->provider_response,
                ]));
            } elseif ($type === 'audit') {
                fputcsv($output, ['Time', 'Actor User ID', 'Action', 'Record Type', 'Record ID', 'Reason', 'Origin IP']);
                AuditEvent::query()->whereBetween('occurred_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->occurred_at,
                    $row->actor_user_id,
                    $row->action_code,
                    $row->record_type,
                    $row->record_id,
                    $row->reason,
                    $row->origin_ip,
                ]));
            } else {
                fputcsv($output, ['Period From', 'Period To', 'Released Custodies', 'Closed Custodies', 'Return Compliance Percent']);
                $compliance = $this->returnCompliance($from, $to);
                fputcsv($output, [
                    $from->toDateString(),
                    $to->toDateString(),
                    $compliance['released'],
                    $compliance['closed'],
                    $compliance['percentage'],
                ]);
            }

            fclose($output);
        }, "spmu-{$type}-report-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function audit(Request $request): View
    {
        abort_unless(
            in_array($request->user()?->access_classification, [
                AccessClassification::SpmuHead,
                AccessClassification::IctuMaintainer,
            ], true),
            403
        );
        return view('reports.audit', [
            'events' => AuditEvent::query()->with('actor')->latest('occurred_at')->limit(500)->get(),
        ]);
    }

    public function notifications(Request $request): View
    {
        abort_unless(
            in_array($request->user()?->access_classification, [
                AccessClassification::SpmuHead,
                AccessClassification::IctuMaintainer,
            ], true),
            403
        );
        return view('reports.notifications', [
            'deliveries' => NotificationDelivery::query()->with('event')->latest('created_at')->limit(500)->get(),
        ]);
    }

    /** @return array{released:int,closed:int,percentage:float} */
    private function returnCompliance(Carbon $from, Carbon $to): array
    {
        $released = CustodyTransaction::query()->whereBetween('released_at', [$from, $to])->count();
        $closed = CustodyTransaction::query()
            ->whereBetween('released_at', [$from, $to])
            ->where('status', 'CLOSED')
            ->count();

        return [
            'released' => $released,
            'closed' => $closed,
            'percentage' => $released ? round($closed / $released * 100, 2) : 0,
        ];
    }
}
