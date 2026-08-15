<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\TemporaryDelegation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user()->load('roles');
        $workspace = $user->primaryWorkspace();
        abort_unless($workspace, 403, 'This account has no valid portal assignment.');

        if ($request->session()->get('active_workspace') !== $workspace) {
            $request->session()->put('active_workspace', $workspace);
        }

        [$statistics, $queue] = match ($workspace) {
            'BORROWER' => [[
                'Open requests' => BorrowingRequest::where('borrower_user_id', $user->id)->whereNotIn('status', [RequestStatus::Cancelled, RequestStatus::Rejected, RequestStatus::Expired])->count(),
                'Active borrowings' => CustodyTransaction::where('borrower_user_id', $user->id)->whereNotIn('status', ['CLOSED'])->count(),
                'Borrowable items' => InventoryItem::where('active', true)->where('borrowable', true)->count(),
            ], BorrowingRequest::with('currentVersion')->where('borrower_user_id', $user->id)->latest()->limit(6)->get()],
            'SPMU' => [[
                'SPMU review queue' => BorrowingRequest::where('status', RequestStatus::UnderSpmu)->count(),
                'Release/return cases' => CustodyTransaction::whereNotIn('status', ['CLOSED'])->count(),
                'Active inventory' => InventoryItem::where('active', true)->count(),
            ], BorrowingRequest::with(['borrower', 'currentVersion'])->whereIn('status', [RequestStatus::UnderSpmu, RequestStatus::ApprovedReadyForRelease])->latest()->limit(6)->get()],
            'GSU' => [[
                'GSU review queue' => BorrowingRequest::where('status', RequestStatus::UnderGsu)->count(),
                'Forwarded approvals' => BorrowingRequest::whereIn('status', [RequestStatus::UnderVpaf, RequestStatus::FinalApprovedAwaitingDownload, RequestStatus::ApprovedReadyForRelease])->count(),
                'Active inventory' => InventoryItem::where('active', true)->count(),
            ], BorrowingRequest::with(['borrower', 'currentVersion'])->where('status', RequestStatus::UnderGsu)->oldest()->limit(6)->get()],
            'VPAF' => [[
                'VPAF review queue' => BorrowingRequest::where('status', RequestStatus::UnderVpaf)->count(),
                'Final approvals' => BorrowingRequest::whereNotNull('final_approved_at')->count(),
                'Active custody' => CustodyTransaction::whereNotIn('status', ['CLOSED'])->count(),
            ], BorrowingRequest::with(['borrower', 'currentVersion'])->where('status', RequestStatus::UnderVpaf)->oldest()->limit(6)->get()],
            default => [[
                'Active accounts' => User::where('account_status', 'ACTIVE')->count(),
                'Failed notifications' => NotificationDelivery::where('delivery_status', 'FAILED')->count(),
                'Active delegations' => TemporaryDelegation::where('status', 'ACTIVE')->whereNull('revoked_at')->where('effective_from', '<=', now())->where('effective_to', '>=', now())->count(),
            ], User::with('organizationalUnit')->latest()->limit(6)->get()],
        };

        $nextCustodies = CustodyTransaction::with(['borrower', 'request'])
            ->when($workspace === 'BORROWER', fn ($query) => $query->where('borrower_user_id', $user->id))
            ->whereNotIn('status', ['CLOSED'])
            ->orderBy('due_at')->limit(5)->get();

        return view('dashboard', compact('statistics', 'user', 'workspace', 'queue', 'nextCustodies'));
    }
}
