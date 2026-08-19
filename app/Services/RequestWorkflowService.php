<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\DownloadEvent;
use App\Models\GatePass;
use App\Models\GeneratedDocument;
use App\Models\RequestStatusHistory;
use App\Models\SystemSetting;
use App\Models\TemporaryDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestWorkflowService
{
    public function __construct(
        private InventoryService $inventory,
        private SignatureService $signatures,
        private DocumentService $documents,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {}

    public function submit(BorrowingRequest $request, User $borrower): void
    {
        if (! $borrower->mayBorrow()) {
            abort(403, 'This account classification is not permitted to borrow.');
        }
        if ($request->borrower_user_id !== $borrower->id || ! in_array($request->status, [RequestStatus::Draft, RequestStatus::ReturnedForRevision], true)) {
            abort(403);
        }
        if ($borrower->activeRestrictions()->exists()) {
            throw ValidationException::withMessages(['restriction' => 'An active borrowing restriction prevents a new submission. Resolve the linked obligation with SPMU.']);
        }

        DB::transaction(function () use ($request, $borrower): void {
            $request->loadMissing('currentVersion.items.inventoryItem');
            $version = $request->currentVersion;
            if (! $version || $version->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Add at least one inventory item before submission.']);
            }
            if ($version->needed_from->isPast() || $version->return_due_at->lte($version->needed_from)) {
                throw ValidationException::withMessages(['needed_from' => 'The borrowing period must start in the future and end after it begins.']);
            }

            foreach ($version->items as $requestItem) {
                $item = $requestItem->inventoryItem;
                $balance = $this->inventory->availability($item, $version->needed_from, $version->return_due_at);
                if (! $item->borrowable || (float) $requestItem->requested_quantity > $balance['available']) {
                    throw ValidationException::withMessages(['items' => "{$item->unique_description} is no longer available in the requested quantity for the complete period."]);
                }
                if ($requestItem->use_location === 'OFF_CAMPUS' && ! $item->off_campus_allowed) {
                    throw ValidationException::withMessages(['locations' => "{$item->unique_description} is restricted to On-Campus use."]);
                }
            }

            $snapshot = $this->signatures->snapshot($borrower, 'BORROWER_REQUEST', UserRole::Borrower->value);
            $version->update([
                'borrower_signature_snapshot_id' => $snapshot->id,
                'accuracy_certified' => true,
                'signed_at' => now(),
                'submitted_at' => now(),
            ]);

            $version->approvalSteps()->delete();
            foreach (ApprovalStage::cases() as $stage) {
                ApprovalStep::query()->create([
                    'request_version_id' => $version->id,
                    'stage_code' => $stage,
                    'sequence_no' => $stage->sequence(),
                    'received_at' => $stage === ApprovalStage::Spmu ? now() : null,
                    'decision' => $stage === ApprovalStage::Spmu ? 'RECEIVED' : 'PENDING',
                ]);
            }

            $this->transition($request, RequestStatus::UnderSpmu, $borrower, 'Borrower certified and submitted request version '.$version->version_no.'.');
            $this->documents->requestLetter($request->fresh(), false);
            $this->audit->record('REQUEST_SUBMITTED', $request, reason: 'Certified request routed to SPMU.');

            $this->notifications->send(
                'REQUEST_SUBMITTED',
                $this->usersWithRole(UserRole::Spmu),
                "Request {$request->request_no} is ready for SPMU review.",
                $request,
                ['SYSTEM', 'EMAIL'],
            );
        }, 3);
    }

    public function decide(BorrowingRequest $request, User $approver, string $decision, ?string $remarks): void
    {
        $stage = $this->currentStage($request->status);
        if (! $stage) {
            abort(403);
        }
        $delegation = $this->delegationForApproval($approver, $stage);
        if ($approver->primaryWorkspace() !== $stage->value && ! $delegation) {
            abort(403);
        }
        if ($request->borrower_user_id === $approver->id) {
            throw ValidationException::withMessages(['decision' => 'Self-approval is prohibited. Another authorized officer must act.']);
        }
        if (! $this->isHeadForStage($approver, $stage) && ! $delegation) {
            throw ValidationException::withMessages(['decision' => 'Only the office Head or a currently authorized delegated approver may complete this approval.']);
        }
        if (in_array($decision, ['REJECTED', 'RETURNED_FOR_REVISION'], true) && blank($remarks)) {
            throw ValidationException::withMessages(['remarks' => 'A reason is required when rejecting or returning a request.']);
        }

        DB::transaction(function () use ($request, $approver, $decision, $remarks, $stage, $delegation): void {
            $request->loadMissing('currentVersion.approvalSteps');
            $step = $request->currentVersion->approvalSteps->firstWhere('stage_code', $stage);
            if (! $step || ! in_array($step->decision, ['PENDING', 'RECEIVED'], true)) {
                throw ValidationException::withMessages(['decision' => 'This approval step has already been completed.']);
            }

            $snapshot = $this->signatures->snapshot($approver, 'APPROVAL_'.$stage->value, $stage->value);
            $step->update([
                'approver_user_id' => $approver->id,
                'signature_snapshot_id' => $snapshot->id,
                'received_at' => $step->received_at ?: now(),
                'decision' => $decision,
                'decided_at' => now(),
                'remarks' => $remarks,
                'temporary_delegation_id' => $delegation?->id,
            ]);

            if ($decision === 'REJECTED') {
                $this->restoreReservationIfPresent($request, 'REJECTED', $remarks ?: 'Request rejected after SPMU reservation.');
                $this->transition($request, RequestStatus::Rejected, $approver, $remarks);
            } elseif ($decision === 'RETURNED_FOR_REVISION') {
                $this->restoreReservationIfPresent($request, 'RETURNED_FOR_REVISION', $remarks ?: 'Request returned for revision after SPMU reservation.');
                $this->transition($request, RequestStatus::ReturnedForRevision, $approver, $remarks);
            } elseif ($stage === ApprovalStage::Spmu) {
                /*
                 * SPMU is the inventory decision point. A submitted request is
                 * only a request; it becomes a reservation after SPMU approves
                 * and this atomic availability check succeeds.
                 */
                try {
                    $this->inventory->allocate($request->currentVersion);
                } catch (ValidationException $exception) {
                    $step->update([
                        'decision' => 'RETURNED_FOR_REVISION',
                        'remarks' => $exception->getMessage(),
                    ]);
                    $this->transition(
                        $request,
                        RequestStatus::ReturnedForRevision,
                        $approver,
                        $exception->getMessage()
                    );
                    $this->notifications->send(
                        'REQUEST_RETURNED_FOR_REVISION',
                        collect([$request->borrower])->merge($this->usersWithRole(UserRole::Spmu))->unique('id'),
                        "Request {$request->request_no} returned because inventory became insufficient. {$exception->getMessage()}",
                        $request
                    );
                    $this->audit->record(
                        'SPMU_APPROVAL_ALLOCATION_CONFLICT',
                        $step,
                        reason: $exception->getMessage(),
                        after: ['decision' => 'RETURNED_FOR_REVISION']
                    );

                    return;
                }

                $next = ApprovalStage::Gsu;
                $request->currentVersion->approvalSteps
                    ->firstWhere('stage_code', $next)
                    ?->update(['received_at' => now(), 'decision' => 'RECEIVED']);
                $this->transition(
                    $request,
                    RequestStatus::UnderGsu,
                    $approver,
                    'SPMU approved the request, reserved the approved quantity, and routed it to GSU.'
                );
                $this->notifications->send(
                    'ROUTED_FOR_APPROVAL',
                    $this->usersWithRole(UserRole::Gsu),
                    "Request {$request->request_no} is ready for GSU review.",
                    $request,
                    ['SYSTEM', 'EMAIL'],
                );
            } elseif ($stage === ApprovalStage::Vpaf) {
                $deadlineTime = SystemSetting::value('approved_letter_download_time', '23:59');
                $deadline = now()->setTimeFromTimeString(is_string($deadlineTime) ? $deadlineTime : '23:59');
                if ($deadline->lte(now())) {
                    $deadline = now()->endOfDay();
                }
                $request->update(['final_approved_at' => now(), 'download_deadline_at' => $deadline]);
                $this->transition($request, RequestStatus::FinalApprovedAwaitingDownload, $approver, 'Final approval completed. Existing SPMU inventory reservation remains active.');
                $document = $this->documents->requestLetter($request->fresh(), true);
                foreach ($request->currentVersion->approvalSteps as $approval) {
                    DB::table('document_approvals')->insert([
                        'generated_document_id' => $document->id,
                        'approval_step_id' => $approval->id,
                        'display_order' => $approval->sequence_no,
                    ]);
                }
                DB::table('kpi_observations')->insert([
                    'request_id' => $request->id,
                    'recorded_by_user_id' => $approver->id,
                    'process_code' => 'DIGITAL_APPROVAL_CYCLE',
                    'started_at' => $request->currentVersion->submitted_at,
                    'completed_at' => now(),
                    'duration_seconds' => $request->currentVersion->submitted_at?->diffInSeconds(now()),
                    'correct_count' => $request->currentVersion->approvalSteps->where('decision', 'APPROVED')->count(),
                    'total_count' => $request->currentVersion->approvalSteps->count(),
                    'output_count' => 1,
                    'input_value' => $request->currentVersion->approvalSteps->count(),
                    'input_unit' => 'approval steps',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->notifications->send(
                    'FINAL_APPROVAL',
                    collect([$request->borrower])->merge($this->usersWithRole(UserRole::Spmu))->unique('id'),
                    "Request {$request->request_no} is fully approved. Download the signed letter by {$deadline->format('g:i A')} today to keep the allocation.",
                    $request,
                );
            } else {
                $next = ApprovalStage::Vpaf;
                $request->currentVersion->approvalSteps
                    ->firstWhere('stage_code', $next)
                    ?->update(['received_at' => now(), 'decision' => 'RECEIVED']);
                $this->transition(
                    $request,
                    RequestStatus::UnderVpaf,
                    $approver,
                    $stage->value.' approved and routed to '.$next->value.'.'
                );
                $this->notifications->send(
                    'ROUTED_FOR_APPROVAL',
                    $this->usersWithRole(UserRole::Vpaf),
                    "Request {$request->request_no} is ready for VPAF review.",
                    $request,
                    ['SYSTEM', 'EMAIL'],
                );
            }

            if ($decision !== 'APPROVED') {
                $this->notifications->send(
                    'REQUEST_'.$decision,
                    collect([$request->borrower]),
                    "Request {$request->request_no} was {$decision}. Reason: {$remarks}",
                    $request,
                );
            }

            $this->audit->record('APPROVAL_DECISION', $step, reason: $remarks, after: ['stage' => $stage->value, 'decision' => $decision]);
        }, 3);
    }

    private function hasActiveReservation(BorrowingRequest $request): bool
    {
        $version = $request->currentVersion;

        if (! $version) {
            return false;
        }

        return $version->items()
            ->whereHas('allocation', fn ($query) => $query->whereIn('status', ['ACTIVE', 'PARTIALLY_RELEASED']))
            ->exists();
    }

    private function restoreReservationIfPresent(BorrowingRequest $request, string $status, string $reason): void
    {
        if ($this->hasActiveReservation($request)) {
            $this->inventory->restore($request, $status, $reason);
        }
    }

    private function isHeadForStage(User $user, ApprovalStage $stage): bool
    {
        $required = match ($stage) {
            ApprovalStage::Spmu => AccessClassification::SpmuHead,
            ApprovalStage::Gsu => AccessClassification::GsuHead,
            ApprovalStage::Vpaf => AccessClassification::VpafHead,
        };

        return $user->access_classification === $required;
    }

    private function delegationForApproval(User $user, ApprovalStage $stage): ?TemporaryDelegation
    {
        return $user->activeDelegationFor($stage->value);
    }

    public function cancel(BorrowingRequest $request, User $actor, string $reason): void
    {
        if ($request->borrower_user_id !== $actor->id && ! $actor->hasRole(UserRole::Spmu)) {
            abort(403);
        }
        if ($request->custody?->released_at) {
            throw ValidationException::withMessages(['cancel' => 'Items have already been released. Use the Early Return process instead of cancellation.']);
        }
        if (in_array($request->status, [RequestStatus::Cancelled, RequestStatus::Expired, RequestStatus::Rejected], true)) {
            throw ValidationException::withMessages(['cancel' => 'This request is already closed.']);
        }

        DB::transaction(function () use ($request, $actor, $reason): void {
            $hasReservation = $this->hasActiveReservation($request);
            $afterApproval = in_array($request->status, [RequestStatus::FinalApprovedAwaitingDownload, RequestStatus::ApprovedReadyForRelease], true);

            if ($hasReservation) {
                $this->inventory->restore($request, 'CANCELLED', $reason);
            }
            DB::table('request_cancellations')->insert([
                'request_id' => $request->id,
                'request_version_id' => $request->currentVersion?->id,
                'cancelled_by_user_id' => $actor->id,
                'phase' => $afterApproval ? 'AFTER_APPROVAL_BEFORE_RELEASE' : 'BEFORE_FINAL_APPROVAL',
                'reason' => $reason,
                'cancelled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            GeneratedDocument::query()->where('request_version_id', $request->currentVersion?->id)->whereIn('status', ['DRAFT', 'FINAL'])->update([
                'status' => 'INVALIDATED', 'invalidated_at' => now(), 'invalidation_reason' => $reason,
            ]);
            $request->custody?->update(['status' => 'CANCELLED', 'closed_at' => now()]);
            $this->transition($request, RequestStatus::Cancelled, $actor, $reason);
            $this->audit->record('REQUEST_CANCELLED', $request, reason: $reason);
            $this->notifications->send('REQUEST_CANCELLED', collect([$request->borrower]), "Request {$request->request_no} was cancelled. {$reason}", $request);
        }, 3);
    }

    public function recordApprovedLetterDownload(BorrowingRequest $request, GeneratedDocument $document, User $borrower, string $ip, ?string $userAgent): CustodyTransaction
    {
        if ($request->borrower_user_id !== $borrower->id || $document->document_type !== 'APPROVED_REQUEST_LETTER' || $document->status !== 'FINAL') {
            abort(403);
        }
        $existingDownload = DownloadEvent::query()
            ->where('generated_document_id', $document->id)
            ->where('downloaded_by_user_id', $borrower->id)
            ->where('integrity_hash', $document->sha256)
            ->exists();
        if ($existingDownload && $request->status === RequestStatus::ApprovedReadyForRelease) {
            return $request->custody()->firstOrFail();
        }
        if ($request->status === RequestStatus::Expired || now()->gt($request->download_deadline_at)) {
            throw ValidationException::withMessages(['download' => 'The approved-letter download deadline has passed. The allocation can no longer be used.']);
        }

        return DB::transaction(function () use ($request, $document, $borrower, $ip, $userAgent): CustodyTransaction {
            DownloadEvent::query()->firstOrCreate([
                'generated_document_id' => $document->id,
                'downloaded_by_user_id' => $borrower->id,
                'integrity_hash' => $document->sha256,
            ], [
                'downloaded_at' => now(),
                'origin_ip' => $ip,
                'user_agent' => $userAgent,
            ]);

            $custody = CustodyTransaction::query()->firstOrCreate([
                'request_id' => $request->id,
            ], [
                'custody_no' => 'CUS-'.now()->format('Ymd').'-'.str_pad((string) $request->id, 5, '0', STR_PAD_LEFT),
                'request_version_id' => $request->currentVersion->id,
                'borrower_user_id' => $borrower->id,
                'status' => 'PREPARING_RELEASE',
                'scheduled_release_at' => $request->currentVersion->needed_from,
                'due_at' => $request->currentVersion->return_due_at,
            ]);
            $request->loadMissing('currentVersion.items.allocation');
            foreach ($request->currentVersion->items as $item) {
                CustodyLine::query()->firstOrCreate([
                    'custody_transaction_id' => $custody->id,
                    'request_item_id' => $item->id,
                ], [
                    'allocation_id' => $item->allocation->id,
                    'approved_quantity' => $item->approved_quantity,
                    'quantity_to_receive' => $item->approved_quantity,
                ]);
            }

            $this->transition($request, RequestStatus::ApprovedReadyForRelease, $borrower, 'Borrower downloaded the exact fully approved letter.');
            if (! GeneratedDocument::query()->where('subject_type', CustodyTransaction::class)->where('subject_id', $custody->id)->where('document_type', 'BORROWER_SLIP')->exists()) {
                $this->documents->borrowerSlip($custody);
            }

            $offCampusLine = $custody->lines()->whereHas('requestItem', fn ($query) => $query->where('use_location', 'OFF_CAMPUS'))->first();
            if ($offCampusLine && ! $custody->gatePass) {
                $passDocument = $this->documents->conditionalForm($custody, 'GATE_PASS');
                GatePass::query()->create([
                    'custody_transaction_id' => $custody->id,
                    'custody_line_id' => $offCampusLine->id,
                    'pass_document_id' => $passDocument->id,
                    'bearer_name' => $borrower->full_name,
                    'destination' => $request->currentVersion->location,
                    'purpose' => $request->currentVersion->purpose_event,
                    'status' => 'PENDING',
                ]);
            }
            if ($custody->lines()->whereHas('requestItem.inventoryItem', fn ($query) => $query->where('laundry_required', true))->exists()
                && ! GeneratedDocument::query()->where('subject_type', CustodyTransaction::class)->where('subject_id', $custody->id)->where('document_type', 'LAUNDRY_FORM')->exists()) {
                $this->documents->conditionalForm($custody, 'LAUNDRY_FORM');
            }

            $this->audit->record('APPROVED_LETTER_DOWNLOADED', $document, after: ['custody_id' => $custody->id, 'sha256' => $document->sha256]);

            return $custody;
        }, 3);
    }

    public function expireUndownloaded(): int
    {
        $count = 0;
        BorrowingRequest::query()
            ->where('status', RequestStatus::FinalApprovedAwaitingDownload->value)
            ->where('download_deadline_at', '<', now())
            ->each(function (BorrowingRequest $request) use (&$count): void {
                DB::transaction(function () use ($request, &$count): void {
                    $this->inventory->restore($request, 'EXPIRED', 'Approved letter was not downloaded by the configured deadline.');
                    GeneratedDocument::query()->where('request_version_id', $request->currentVersion?->id)->where('status', 'FINAL')->update([
                        'status' => 'EXPIRED', 'invalidated_at' => now(), 'invalidation_reason' => 'Download deadline missed.',
                    ]);
                    $this->transition($request, RequestStatus::Expired, null, 'Approved letter was not downloaded by the configured deadline.');
                    $this->notifications->send('REQUEST_EXPIRED', collect([$request->borrower]), "Request {$request->request_no} expired because the approved letter was not downloaded by the deadline.", $request);
                    $count++;
                });
            });

        return $count;
    }

    private function transition(BorrowingRequest $request, RequestStatus $to, ?User $actor, ?string $reason): void
    {
        $from = $request->status;
        $request->update(['status' => $to]);
        RequestStatusHistory::query()->create([
            'request_id' => $request->id,
            'request_version_id' => $request->currentVersion?->id,
            'actor_user_id' => $actor?->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'changed_at' => now(),
        ]);
    }

    private function currentStage(RequestStatus $status): ?ApprovalStage
    {
        return match ($status) {
            RequestStatus::UnderSpmu => ApprovalStage::Spmu,
            RequestStatus::UnderGsu => ApprovalStage::Gsu,
            RequestStatus::UnderVpaf => ApprovalStage::Vpaf,
            default => null,
        };
    }

    private function usersWithRole(UserRole $role)
    {
        return User::query()
            ->where('account_status', 'ACTIVE')
            ->whereHas('roles', fn ($query) => $query->where('role_code', $role->value)->whereNull('user_roles.revoked_at'))
            ->get();
    }
}
