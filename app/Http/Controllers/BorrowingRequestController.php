<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Services\DocumentService;
use App\Services\InventoryService;
use App\Services\RequestWorkflowService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BorrowingRequestController extends Controller
{
    public function index(Request $request): View
    {
        $query = BorrowingRequest::query()->with(['borrower', 'currentVersion.items', 'custody'])->latest();
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));

        if ($workspace === 'BORROWER') {
            $query->where('borrower_user_id', $request->user()->id);
        } elseif (in_array($workspace, ['GSU', 'VPAF'], true)) {
            $query->where(function ($query) use ($request, $workspace): void {
                $query->where('status', 'UNDER_'.$workspace)
                    ->orWhereHas(
                        'currentVersion.approvalSteps',
                        fn ($step) => $step
                            ->where('stage_code', $workspace)
                            ->where('approver_user_id', $request->user()->id)
                    );
            });
        }

        return view('requests.index', ['requests' => $query->get()]);
    }

    public function create(): View
    {
        return view('requests.form', [
            'borrowingRequest' => new BorrowingRequest,
            'version' => new RequestVersion,
            'items' => InventoryItem::with('unit')
                ->where('active', true)
                ->where('borrowable', true)
                ->orderBy('unique_description')
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        InventoryService $inventory,
        DocumentService $documents
    ): RedirectResponse {
        $data = $this->validateRequest($request);
        $user = $request->user();

        if ($user->activeRestrictions()->exists()) {
            throw ValidationException::withMessages([
                'request' => 'An active borrowing restriction prevents a new request.',
            ]);
        }

        $borrowingRequest = DB::transaction(
            function () use ($data, $user, $inventory): BorrowingRequest {
                $borrowingRequest = BorrowingRequest::query()->create([
                    'request_no' => 'BR-'.now()->format('YmdHis').'-'.$user->id,
                    'borrower_user_id' => $user->id,
                    'accountable_unit_id' => $user->organizational_unit_id,
                    'current_version_no' => 1,
                    'status' => RequestStatus::Draft,
                ]);

                $version = $borrowingRequest->versions()->create(
                    $this->versionData($data, $user->id, 1)
                );

                $this->saveItems($version, $data, $inventory);

                return $borrowingRequest;
            }
        );

        $documents->requestLetter($borrowingRequest->fresh(), false);

        return redirect()
            ->route('requests.show', $borrowingRequest)
            ->with(
                'status',
                'Draft request and official preview generated. Review the letter before certifying and submitting it.'
            );
    }

    public function show(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): View {
        $canDecide = $this->authorizeRequest($request, $borrowingRequest);
        $approvalStage = $this->approvalStage($borrowingRequest->status);

        $borrowingRequest->load([
            'borrower.organizationalUnit',
            'accountableUnit',
            'currentVersion.items.inventoryItem.unit',
            'currentVersion.approvalSteps.approver',
            'currentVersion.documents.downloads',
            'statusHistory.actor',
            'custody.lines.requestItem.inventoryItem',
        ]);

        return view(
            'requests.show',
            compact('borrowingRequest', 'canDecide', 'approvalStage')
        );
    }

    public function edit(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): View {
        abort_unless(
            $borrowingRequest->borrower_user_id === $request->user()->id
            && in_array(
                $borrowingRequest->status,
                [
                    RequestStatus::Draft,
                    RequestStatus::ReturnedForRevision,
                ],
                true
            ),
            403
        );

        $borrowingRequest->load('currentVersion.items');

        return view('requests.form', [
            'borrowingRequest' => $borrowingRequest,
            'version' => $borrowingRequest->currentVersion,
            'items' => InventoryItem::with('unit')
                ->where('active', true)
                ->where('borrowable', true)
                ->orderBy('unique_description')
                ->get(),
        ]);
    }

    public function update(
        Request $request,
        BorrowingRequest $borrowingRequest,
        InventoryService $inventory,
        DocumentService $documents
    ): RedirectResponse {
        abort_unless(
            $borrowingRequest->borrower_user_id === $request->user()->id
            && in_array(
                $borrowingRequest->status,
                [
                    RequestStatus::Draft,
                    RequestStatus::ReturnedForRevision,
                ],
                true
            ),
            403
        );

        $data = $this->validateRequest($request);

        DB::transaction(
            function () use (
                $borrowingRequest,
                $data,
                $request,
                $inventory
            ): void {
                $versionNo = $borrowingRequest->status === RequestStatus::ReturnedForRevision
                    ? $borrowingRequest->current_version_no + 1
                    : $borrowingRequest->current_version_no;

                if ($borrowingRequest->status === RequestStatus::ReturnedForRevision) {
                    $version = $borrowingRequest->versions()->create(
                        $this->versionData(
                            $data,
                            $request->user()->id,
                            $versionNo
                        )
                    );

                    $borrowingRequest->update([
                        'current_version_no' => $versionNo,
                        'status' => RequestStatus::Draft,
                    ]);
                } else {
                    $version = $borrowingRequest->currentVersion;

                    $version->update(
                        $this->versionData(
                            $data,
                            $request->user()->id,
                            $versionNo
                        )
                    );

                    $version->items()->delete();
                }

                $this->saveItems($version, $data, $inventory);
            }
        );

        $borrowingRequest
            ->fresh()
            ->currentVersion
            ->documents()
            ->where('document_type', 'REQUEST_LETTER')
            ->where('status', 'DRAFT')
            ->update([
                'status' => 'SUPERSEDED',
                'invalidated_at' => now(),
                'invalidation_reason' => 'Draft was regenerated after borrower editing.',
            ]);

        $documents->requestLetter($borrowingRequest->fresh(), false);

        return redirect()
            ->route('requests.show', $borrowingRequest)
            ->with('status', 'Request draft updated.');
    }

    public function submit(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $workflow->submit($borrowingRequest, $request->user());

        return back()->with(
            'status',
            'Request signed and submitted to SPMU. Pending requests do not reserve inventory.'
        );
    }

    public function recoverDraftDocument(
        Request $request,
        BorrowingRequest $borrowingRequest,
        DocumentService $documents
    ): RedirectResponse {
        abort_unless(
            $borrowingRequest->borrower_user_id === $request->user()->id,
            403
        );

        $result = $documents->recoverMissingDraftRequestLetter(
            $borrowingRequest
        );

        return back()->with(
            'status',
            $result['generated']
                ? 'The missing draft request-letter preview was regenerated successfully.'
                : 'The draft request-letter preview is already available. No duplicate was created.'
        );
    }

    public function cancel(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $workflow->cancel(
            $borrowingRequest,
            $request->user(),
            $data['reason']
        );

        return back()->with(
            'status',
            'Request cancelled and any unreleased allocation restored.'
        );
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'purpose_event' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'needed_from' => ['required', 'date', 'after:now'],
            'return_due_at' => ['required', 'date', 'after:needed_from'],

            'student_organization' => [
                'nullable',
                'string',
                'max:255',
            ],

            'represents_student_activity' => [
                'nullable',
                'boolean',
            ],

            'represented_program_department' => [
                'required_if:represents_student_activity,1',
                'nullable',
                'string',
                'max:255',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'off_campus' => [
                'nullable',
                'boolean',
            ],

            'item_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'item_ids.*' => [
                'required',
                'integer',
                'exists:inventory_items,id',
            ],

            'quantities' => [
                'required',
                'array',
            ],

            'quantities.*' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'locations' => [
                'nullable',
                'array',
            ],

            'locations.*' => [
                'nullable',
                Rule::in([
                    'ON_CAMPUS',
                    'OFF_CAMPUS',
                ]),
            ],
        ]);
    }

    private function versionData(
        array $data,
        int $userId,
        int $versionNo
    ): array {
        return [
            'version_no' => $versionNo,
            'purpose_event' => $data['purpose_event'],
            'location' => $data['location'],
            'needed_from' => $data['needed_from'],
            'return_due_at' => $data['return_due_at'],

            'represents_student_activity' => (bool) (
                $data['represents_student_activity'] ?? false
            ),

            'student_organization' => $data['student_organization'] ?? null,

            'represented_program_department' =>
                $data['represented_program_department'] ?? null,

            // Legacy field retained in the database/model.
            // It is no longer collected from the revised form.
            'represented_year_level' => null,

            // Legacy field retained in the database/model.
            // It is no longer collected from the revised form.
            'event_details' => null,

            'off_campus' => collect(
                $data['locations'] ?? []
            )->contains('OFF_CAMPUS'),

            'remarks' => $data['remarks'] ?? null,
            'created_by_user_id' => $userId,
        ];
    }

    private function saveItems(
        RequestVersion $version,
        array $data,
        InventoryService $inventory
    ): void {
        $selected = 0;

        foreach ($data['item_ids'] as $itemId) {
            $quantity = (float) (
                $data['quantities'][$itemId] ?? 0
            );

            if ($quantity <= 0) {
                continue;
            }

            $item = InventoryItem::with('unit')
                ->where('active', true)
                ->where('borrowable', true)
                ->findOrFail($itemId);

            $balance = $inventory->availability(
                $item,
                Carbon::parse($data['needed_from']),
                Carbon::parse($data['return_due_at'])
            );

            if ($quantity > $balance['available']) {
                throw ValidationException::withMessages([
                    'quantities' => "{$item->unique_description} has only {$balance['available']} available for the complete period.",
                ]);
            }

            $location = strtoupper(
                (string) (
                    $data['locations'][$item->id]
                    ?? 'ON_CAMPUS'
                )
            );

            if (
                ! $item->off_campus_allowed
                && $location !== 'ON_CAMPUS'
            ) {
                throw ValidationException::withMessages([
                    'locations' => "{$item->unique_description} is restricted to On-Campus use.",
                ]);
            }

            if (
                ! in_array(
                    $location,
                    [
                        'ON_CAMPUS',
                        'OFF_CAMPUS',
                    ],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'locations' => 'Choose a valid campus location for each selected item.',
                ]);
            }

            RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $item->id,
                'description_snapshot' => $item->unique_description,
                'unit_snapshot' => $item->unit->unit_name,
                'requested_quantity' => $quantity,
                'use_location' => $location,
            ]);

            $selected++;
        }

        if ($selected === 0) {
            throw ValidationException::withMessages([
                'items' => 'Enter a quantity greater than zero for at least one item.',
            ]);
        }
    }

    private function authorizeRequest(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): bool {
        $user = $request->user();
        $workspace = strtoupper(
            (string) $request->session()->get('active_workspace')
        );

        $assignedOfficer = in_array(
            $workspace,
            ['GSU', 'VPAF'],
            true
        ) && (
            $borrowingRequest->status->value === 'UNDER_'.$workspace
            || $borrowingRequest
                ->currentVersion
                ?->approvalSteps()
                ->where('stage_code', $workspace)
                ->where('approver_user_id', $user->id)
                ->exists()
        );

        $canDecide = $this->canDecideApproval(
            $request,
            $borrowingRequest
        );

        abort_unless(
            (
                $workspace === 'BORROWER'
                && $borrowingRequest->borrower_user_id === $user->id
            )
            || $workspace === 'SPMU'
            || $assignedOfficer
            || $canDecide,
            403
        );

        return $canDecide;
    }

    private function canDecideApproval(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): bool {
        $stage = $this->approvalStage(
            $borrowingRequest->status
        );

        $user = $request->user();

        if (
            ! $stage
            || $borrowingRequest->borrower_user_id === $user->id
        ) {
            return false;
        }

        $headClassification = match ($stage) {
            'SPMU' => AccessClassification::SpmuHead,
            'GSU' => AccessClassification::GsuHead,
            default => AccessClassification::VpafHead,
        };

        return $user->access_classification === $headClassification
            || (bool) $user->activeDelegationFor($stage);
    }

    private function approvalStage(
        RequestStatus $status
    ): ?string {
        return match ($status) {
            RequestStatus::UnderSpmu => 'SPMU',
            RequestStatus::UnderGsu => 'GSU',
            RequestStatus::UnderVpaf => 'VPAF',
            default => null,
        };
    }
}