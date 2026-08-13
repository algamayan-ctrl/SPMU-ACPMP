<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Services\RequestWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $status = match (strtoupper((string) $request->session()->get('active_workspace'))) {
            'SPMU' => RequestStatus::UnderSpmu,
            'GSU' => RequestStatus::UnderGsu,
            default => RequestStatus::UnderVpaf,
        };

        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        $headClassification = match ($workspace) {
            'SPMU' => AccessClassification::SpmuHead,
            'GSU' => AccessClassification::GsuHead,
            default => AccessClassification::VpafHead,
        };

        return view('approvals.index', [
            'stage' => str_replace('UNDER_', '', $status->value),
            'requests' => BorrowingRequest::with(['borrower.organizationalUnit', 'currentVersion.items'])->where('status', $status->value)->oldest()->get(),
            'canDecide' => $request->user()->access_classification === $headClassification || (bool) $request->user()->activeDelegationFor($workspace),
        ]);
    }

    public function decide(Request $request, BorrowingRequest $borrowingRequest, RequestWorkflowService $workflow): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED', 'RETURNED_FOR_REVISION'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $workflow->decide($borrowingRequest, $request->user(), $data['decision'], $data['remarks'] ?? null);

        return redirect()->route('approvals.index')->with('status', 'Approval decision recorded with name, role, timestamps, and immutable e-signature snapshot.');
    }
}
