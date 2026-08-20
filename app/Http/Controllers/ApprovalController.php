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
        abort_unless($request->user()->primaryWorkspace() === 'SPMU', 403);

        return view('approvals.index', [
            'stage' => 'SPMU',
            'requests' => BorrowingRequest::query()
                ->with([
                    'borrower.organizationalUnit',
                    'currentVersion.items.inventoryItem.unit',
                    'currentVersion.supportingDocuments.file',
                ])
                ->where('status', RequestStatus::UnderSpmu)
                ->oldest()
                ->get(),
            'canDecide' =>
                $request->user()->access_classification
                    === AccessClassification::SpmuHead
                || (
                    $request->user()->access_classification
                        === AccessClassification::SpmuOfficer
                    && $request->user()
                        ->activeDelegationFor('SPMU')
                        !== null
                ),
        ]);
    }

    public function decide(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $rules = [
            'decision' => [
                'required',
                Rule::in(['APPROVED', 'REJECTED', 'RETURNED_FOR_REVISION']),
            ],
            'remarks' => [
                Rule::requiredIf(
                    fn (): bool => in_array(
                        strtoupper((string) $request->input('decision')),
                        ['REJECTED', 'RETURNED_FOR_REVISION'],
                        true
                    )
                ),
                'nullable',
                'string',
                'max:2000',
            ],
        ];

        if (
            strtoupper((string) $request->input('decision'))
                === 'APPROVED'
        ) {
            $rules = array_merge(
                $rules,
                [
                    'details_complete' => [
                        'required',
                        'accepted',
                    ],

                    'signatures_present' => [
                        'required',
                        'accepted',
                    ],

                    'document_readable' => [
                        'required',
                        'accepted',
                    ],
                ]
            );
        }

        $data = $request->validate(
            $rules,
            [
                'details_complete.accepted' =>
                    'Confirm that the required details are complete before approving.',

                'signatures_present.accepted' =>
                    'Confirm that the required signatures are present before approving.',

                'document_readable.accepted' =>
                    'Confirm that the scanned document is clear and readable before approving.',

                'remarks.required' =>
                    'Remarks are required when returning a request for revision or rejecting it.',
            ]
        );

        $workflow->decide(
            $borrowingRequest,
            $request->user(),
            $data['decision'],
            $data['remarks'] ?? null
        );

        $message = match ($data['decision']) {
            'APPROVED' => 'SPMU verification completed. The verified approved quantity is reserved and the request is ready for pickup scheduling.',
            'RETURNED_FOR_REVISION' => 'Request returned for revision. No reservation was retained for this decision.',
            default => 'Request rejected by SPMU. No reservation was created.',
        };

        return redirect()
            ->route('approvals.index')
            ->with('status', $message);
    }
}
