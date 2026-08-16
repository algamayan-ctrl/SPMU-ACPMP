<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\TemporaryDelegation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user()->load('roles');

        $workspace = strtoupper(
            (string) $request->session()->get('active_workspace')
        );

        $allowed = $user->allowedWorkspaces();

        if (! in_array($workspace, $allowed, true)) {
            $workspace = $user->access_classification?->primaryWorkspace()
                ?? $allowed[0]
                ?? null;

            abort_unless(
                $workspace,
                403,
                'No system role is assigned to this account.'
            );

            $request->session()->put(
                'active_workspace',
                $workspace
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Custody states that mean physical release already happened
        |--------------------------------------------------------------------------
        |
        | Once a request reaches one of these states, the operational status
        | shown to the borrower must come from custody rather than the original
        | APPROVED_READY_FOR_RELEASE request status.
        |
        */

        $postReleaseCustodyStatuses = [
            'ACTIVE',
            'PARTIALLY_RETURNED',
            'OVERDUE',
            'EARLY_RETURN',
            'INCIDENT_OPEN',
            'OBLIGATION_OPEN',
            'CLOSED',
        ];

        [$statistics, $queue] = match ($workspace) {

            /*
            |--------------------------------------------------------------------------
            | BORROWER DASHBOARD
            |--------------------------------------------------------------------------
            */

            'BORROWER' => [
                [
                    /*
                     * A request is considered "open" here while it is still in
                     * request/release preparation workflow.
                     *
                     * Once physical release has happened, it is represented
                     * under My Borrowings / Active Borrowings instead.
                     */
                    'Open requests' => BorrowingRequest::query()
                        ->where(
                            'borrower_user_id',
                            $user->id
                        )
                        ->whereNotIn(
                            'status',
                            [
                                RequestStatus::Cancelled,
                                RequestStatus::Rejected,
                                RequestStatus::Expired,
                            ]
                        )
                        ->whereDoesntHave(
                            'custody',
                            function ($query) use ($postReleaseCustodyStatuses): void {
                                $query->whereIn(
                                    'status',
                                    $postReleaseCustodyStatuses
                                );
                            }
                        )
                        ->count(),

                    /*
                     * Existing open custody records.
                     *
                     * PREPARING_RELEASE remains included because it is already
                     * an active Borrower's Slip/release case.
                     */
                    'Active borrowings' => CustodyTransaction::query()
                        ->where(
                            'borrower_user_id',
                            $user->id
                        )
                        ->whereNotIn(
                            'status',
                            ['CLOSED']
                        )
                        ->count(),

                    /*
                     * Number of active borrowable inventory records.
                     *
                     * The Blade template presents this label as
                     * "Available items".
                     */
                    'Borrowable items' => InventoryItem::query()
                        ->where('active', true)
                        ->where('borrowable', true)
                        ->count(),
                ],

                /*
                 * IMPORTANT:
                 *
                 * custody must be eager-loaded because the dashboard uses its
                 * status to determine whether a request is already Released,
                 * Partially Returned, Overdue, etc.
                 */
                BorrowingRequest::query()
                    ->with([
                        'currentVersion',
                        'custody',
                    ])
                    ->where(
                        'borrower_user_id',
                        $user->id
                    )
                    ->latest()
                    ->limit(6)
                    ->get(),
            ],


            /*
            |--------------------------------------------------------------------------
            | SPMU DASHBOARD
            |--------------------------------------------------------------------------
            */

            'SPMU' => [
                [
                    'SPMU review queue' => BorrowingRequest::query()
                        ->where(
                            'status',
                            RequestStatus::UnderSpmu
                        )
                        ->count(),

                    'Release/return cases' => CustodyTransaction::query()
                        ->whereNotIn(
                            'status',
                            ['CLOSED']
                        )
                        ->count(),

                    'Active inventory' => InventoryItem::query()
                        ->where('active', true)
                        ->count(),
                ],

                BorrowingRequest::query()
                    ->with([
                        'borrower',
                        'currentVersion',
                        'custody',
                    ])
                    ->whereIn(
                        'status',
                        [
                            RequestStatus::UnderSpmu,
                            RequestStatus::ApprovedReadyForRelease,
                        ]
                    )
                    ->latest()
                    ->limit(6)
                    ->get(),
            ],


            /*
            |--------------------------------------------------------------------------
            | GSU DASHBOARD
            |--------------------------------------------------------------------------
            */

            'GSU' => [
                [
                    'GSU review queue' => BorrowingRequest::query()
                        ->where(
                            'status',
                            RequestStatus::UnderGsu
                        )
                        ->count(),

                    'Forwarded approvals' => BorrowingRequest::query()
                        ->whereIn(
                            'status',
                            [
                                RequestStatus::UnderVpaf,
                                RequestStatus::FinalApprovedAwaitingDownload,
                                RequestStatus::ApprovedReadyForRelease,
                            ]
                        )
                        ->count(),

                    'Active inventory' => InventoryItem::query()
                        ->where('active', true)
                        ->count(),
                ],

                BorrowingRequest::query()
                    ->with([
                        'borrower',
                        'currentVersion',
                        'custody',
                    ])
                    ->where(
                        'status',
                        RequestStatus::UnderGsu
                    )
                    ->oldest()
                    ->limit(6)
                    ->get(),
            ],


            /*
            |--------------------------------------------------------------------------
            | VPAF DASHBOARD
            |--------------------------------------------------------------------------
            */

            'VPAF' => [
                [
                    'VPAF review queue' => BorrowingRequest::query()
                        ->where(
                            'status',
                            RequestStatus::UnderVpaf
                        )
                        ->count(),

                    'Final approvals' => BorrowingRequest::query()
                        ->whereNotNull(
                            'final_approved_at'
                        )
                        ->count(),

                    'Active custody' => CustodyTransaction::query()
                        ->whereNotIn(
                            'status',
                            ['CLOSED']
                        )
                        ->count(),
                ],

                BorrowingRequest::query()
                    ->with([
                        'borrower',
                        'currentVersion',
                        'custody',
                    ])
                    ->where(
                        'status',
                        RequestStatus::UnderVpaf
                    )
                    ->oldest()
                    ->limit(6)
                    ->get(),
            ],


            /*
            |--------------------------------------------------------------------------
            | ICTU DASHBOARD
            |--------------------------------------------------------------------------
            */

            default => [
                [
                    'Active accounts' => User::query()
                        ->where(
                            'account_status',
                            'ACTIVE'
                        )
                        ->count(),

                    'Failed notifications' => NotificationDelivery::query()
                        ->where(
                            'delivery_status',
                            'FAILED'
                        )
                        ->count(),

                    'Active delegations' => TemporaryDelegation::query()
                        ->where(
                            'status',
                            'ACTIVE'
                        )
                        ->whereNull(
                            'revoked_at'
                        )
                        ->where(
                            'effective_from',
                            '<=',
                            now()
                        )
                        ->where(
                            'effective_to',
                            '>=',
                            now()
                        )
                        ->count(),
                ],

                User::query()
                    ->with(
                        'organizationalUnit'
                    )
                    ->latest()
                    ->limit(6)
                    ->get(),
            ],
        };


        /*
        |--------------------------------------------------------------------------
        | Upcoming custody deadlines
        |--------------------------------------------------------------------------
        */

        $nextCustodies = CustodyTransaction::query()
            ->with([
                'borrower',
                'request',
            ])
            ->when(
                $workspace === 'BORROWER',
                fn ($query) => $query->where(
                    'borrower_user_id',
                    $user->id
                )
            )
            ->whereNotIn(
                'status',
                ['CLOSED']
            )
            ->orderBy(
                'due_at'
            )
            ->limit(5)
            ->get();


        return view(
            'dashboard',
            compact(
                'statistics',
                'user',
                'workspace',
                'queue',
                'nextCustodies'
            )
        );
    }
}