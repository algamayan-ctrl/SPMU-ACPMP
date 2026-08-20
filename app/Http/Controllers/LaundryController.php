<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\LaundryJob;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LaundryController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeLaundryWorker($request);

        return view('laundry.index', [
            'jobs' => LaundryJob::query()
                ->with([
                    'custody.borrower',
                    'custody.request',
                    'lines.custodyLine.requestItem.inventoryItem.unit',
                ])
                ->where('status', '!=', 'LAUNDRY_COMPLETED')
                ->orderByRaw(
                    "CASE
                        WHEN status = 'FORM_REPLACEMENT_REQUIRED' THEN 0
                        WHEN status = 'FOR_LAUNDRY' THEN 1
                        WHEN status = 'READY_FOR_PICKUP' THEN 2
                        WHEN status = 'FOR_SPMU_FINAL_CHECK' THEN 3
                        ELSE 4
                    END"
                )
                ->latest('updated_at')
                ->paginate(20),
        ]);
    }

    public function show(Request $request, LaundryJob $laundryJob): View
    {
        $this->authorizeLaundryWorker($request);

        $laundryJob->load([
            'custody.borrower',
            'custody.request.currentVersion',
            'document.file',
            'latestEvidence.file',
            'lines.custodyLine.requestItem.inventoryItem.unit',
        ]);

        return view('laundry.show', [
            'job' => $laundryJob,
        ]);
    }

    public function upload(
        Request $request,
        LaundryJob $laundryJob,
        ProtectedFileService $files,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeLaundryWorker($request);

        $maxKb = ((int) \App\Models\SystemSetting::value('max_upload_mb', 5)) * 1024;

        $data = $request->validate([
            'evidence' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:'.$maxKb,
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $laundryJob,
            $files,
            $audit,
            $notifications,
            $data
        ): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->findOrFail($laundryJob->id);

            if (! in_array(
                $job->status,
                ['FOR_LAUNDRY', 'FORM_REPLACEMENT_REQUIRED'],
                true
            )) {
                throw ValidationException::withMessages([
                    'evidence' =>
                        'This laundry request is not awaiting an accomplished Laundry Form.',
                ]);
            }

            $job->loadMissing('custody.borrower');

            $document = $this->currentLaundryDocument($job);

            $file = $files->storeUpload(
                $data['evidence'],
                'laundry-evidence',
                'PAPER_EVIDENCE'
            );

            $submission = EvidenceSubmission::query()->create([
                'generated_document_id' => $document->id,
                'stored_file_id' => $file->id,
                'borrower_user_id' => $job->custody->borrower_user_id,
                'uploaded_by_user_id' => $request->user()->id,
                'upload_mode' => 'LAUNDRY_WORKER',
                'submitted_at' => now(),
                'verification_status' => 'PENDING_VERIFICATION',
            ]);

            $alreadyReleased =
                (bool) $job->released_to_borrower_at;

            $nextStatus =
                $alreadyReleased
                    ? 'FOR_SPMU_FINAL_CHECK'
                    : 'READY_FOR_PICKUP';

            $job->update([
                'generated_document_id' => $document->id,
                'latest_evidence_submission_id' => $submission->id,
                'status' => $nextStatus,
                'ready_at' => $job->ready_at ?: now(),
                'form_verified_by_user_id' => null,
                'form_verified_at' => null,
            ]);

            $audit->record(
                'LAUNDRY_FORM_UPLOADED_BY_WORKER',
                $job,
                after: [
                    'evidence_submission_id' => $submission->id,
                    'status' => $nextStatus,
                ]
            );

            if (! $alreadyReleased) {
                $notifications->send(
                    'LAUNDRY_READY_FOR_PICKUP',
                    collect([$job->custody->borrower]),
                    "Laundry request for {$job->custody->custody_no} is ready for pickup at the Laundry Area. After pickup, return the cleaned linen to SPMU for final inspection.",
                    $job,
                    ['SYSTEM', 'EMAIL']
                );
            }

            $notifications->send(
                'LAUNDRY_FORM_PENDING_SPMU_VERIFICATION',
                $this->spmuRecipients(),
                "Laundry Worker uploaded the accomplished Laundry Form for {$job->custody->custody_no}. SPMU must verify the scan and encode the form before final return.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return redirect()
            ->route('laundry.show', $laundryJob)
            ->with(
                'status',
                'Accomplished Laundry Form uploaded. SPMU was notified for verification; the borrower is notified when the linen first becomes ready for pickup.'
            );
    }

    public function releaseToBorrower(
        Request $request,
        LaundryJob $laundryJob,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeLaundryWorker($request);

        DB::transaction(function () use (
            $request,
            $laundryJob,
            $audit,
            $notifications
        ): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->findOrFail($laundryJob->id);

            if ($job->status === 'FOR_SPMU_FINAL_CHECK') {
                return;
            }

            if ($job->status !== 'READY_FOR_PICKUP') {
                throw ValidationException::withMessages([
                    'laundry' =>
                        'Upload the accomplished Laundry Form first before releasing the cleaned linen to the borrower.',
                ]);
            }

            $job->loadMissing('custody.borrower');

            $job->update([
                'status' => 'FOR_SPMU_FINAL_CHECK',
                'released_to_borrower_at' => now(),
            ]);

            $audit->record(
                'LAUNDRY_RELEASED_TO_BORROWER',
                $job,
                after: [
                    'released_to_borrower_at' => now()->toIso8601String(),
                    'released_by_user_id' => $request->user()->id,
                ]
            );

            $notifications->send(
                'LAUNDRY_RETURN_TO_SPMU_REQUIRED',
                collect([$job->custody->borrower]),
                "Cleaned linen for {$job->custody->custody_no} was released to you by Laundry. Please bring it to SPMU for final quantity and condition inspection.",
                $job,
                ['SYSTEM', 'EMAIL']
            );

            $notifications->send(
                'LAUNDRY_FOR_FINAL_SPMU_CHECK',
                $this->spmuRecipients(),
                "Cleaned linen for {$job->custody->custody_no} was released back to the borrower and is now for final SPMU return inspection.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return back()->with(
            'status',
            'Cleaned linen marked Released to Borrower. The borrower was instructed to return it to SPMU.'
        );
    }

    public function verifyForm(
        Request $request,
        LaundryJob $laundryJob,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Only the SPMU Action Officer may perform the operational Laundry Form verification.'
        );

        $decisionData = $request->validate([
            'decision' => [
                'required',
                Rule::in([
                    'VERIFIED',
                    'REJECTED',
                ]),
            ],
            'rejection_reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if (
            $decisionData['decision'] === 'REJECTED'
            && blank($decisionData['rejection_reason'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'rejection_reason' =>
                    'Explain why a replacement Laundry Form scan is required.',
            ]);
        }

        $verificationData = [];

        if ($decisionData['decision'] === 'VERIFIED') {
            $verificationData = $request->validate([
                'worker_name' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'worker_received_at' => [
                    'required',
                    'date',
                ],
                'worker_completed_at' => [
                    'required',
                    'date',
                    'after_or_equal:worker_received_at',
                ],
                'worker_remarks' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
                'lines' => [
                    'required',
                    'array',
                ],
                'lines.*.received_quantity' => [
                    'required',
                    'numeric',
                    'min:0',
                ],
                'lines.*.issue_type' => [
                    'required',
                    Rule::in([
                        'NONE',
                        'STAINED',
                        'TORN',
                        'DAMAGED',
                        'OTHER',
                    ]),
                ],
                'lines.*.affected_quantity' => [
                    'required',
                    'numeric',
                    'min:0',
                ],
                'lines.*.completed_quantity' => [
                    'required',
                    'numeric',
                    'min:0',
                ],
                'lines.*.remarks' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ]);
        }

        DB::transaction(function () use (
            $request,
            $laundryJob,
            $audit,
            $notifications,
            $decisionData,
            $verificationData
        ): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->with([
                    'latestEvidence',
                    'lines',
                    'custody.borrower',
                ])
                ->findOrFail($laundryJob->id);

            $evidence = $job->latestEvidence;

            if (
                ! $evidence
                || $evidence->verification_status !== 'PENDING_VERIFICATION'
            ) {
                throw ValidationException::withMessages([
                    'laundry' =>
                        'There is no current Laundry Worker scan awaiting SPMU verification.',
                ]);
            }

            if ($decisionData['decision'] === 'REJECTED') {
                $evidence->update([
                    'verified_by_user_id' => $request->user()->id,
                    'verification_status' => 'REJECTED',
                    'verified_at' => now(),
                    'rejection_reason' => $decisionData['rejection_reason'],
                ]);

                $job->update([
                    'status' => 'FORM_REPLACEMENT_REQUIRED',
                    'form_verified_by_user_id' => null,
                    'form_verified_at' => null,
                ]);

                $audit->record(
                    'LAUNDRY_FORM_REJECTED',
                    $job,
                    reason: $decisionData['rejection_reason']
                );

                $notifications->send(
                    'LAUNDRY_FORM_REPLACEMENT_REQUIRED',
                    $this->laundryRecipients(),
                    "SPMU requested a replacement Laundry Form scan for {$job->custody->custody_no}. Reason: {$decisionData['rejection_reason']}",
                    $job,
                    ['SYSTEM']
                );

                return;
            }

            foreach ($job->lines as $line) {
                $values = $verificationData['lines'][$line->id] ?? null;

                if (! is_array($values)) {
                    throw ValidationException::withMessages([
                        'lines' =>
                            'Encode every linen item exactly as written on the accomplished Laundry Form.',
                    ]);
                }

                $issued = (float) $line->issued_quantity;
                $received = (float) $values['received_quantity'];
                $affected = (float) $values['affected_quantity'];
                $completed = (float) $values['completed_quantity'];
                $issue = $values['issue_type'];

                if ($received > $issued) {
                    throw ValidationException::withMessages([
                        'lines' =>
                            'Laundry received quantity cannot exceed the quantity issued by SPMU.',
                    ]);
                }

                if ($affected > $received) {
                    throw ValidationException::withMessages([
                        'lines' =>
                            'Affected quantity cannot exceed the quantity received by Laundry.',
                    ]);
                }

                if ($completed > $received) {
                    throw ValidationException::withMessages([
                        'lines' =>
                            'Completed quantity cannot exceed the quantity received by Laundry.',
                    ]);
                }

                if (
                    $issue === 'NONE'
                    && $affected > 0
                ) {
                    throw ValidationException::withMessages([
                        'lines' =>
                            'Affected quantity must be zero when the Laundry Form reports no issue.',
                    ]);
                }

                if (
                    $issue !== 'NONE'
                    && $affected <= 0
                ) {
                    throw ValidationException::withMessages([
                        'lines' =>
                            'Enter the affected quantity when the Laundry Form reports a stain, tear, damage, or other issue.',
                    ]);
                }

                $line->update([
                    'received_quantity' => $received,
                    'issue_type' => $issue,
                    'affected_quantity' => $affected,
                    'completed_quantity' => $completed,
                    'remarks' => $values['remarks'] ?? null,
                ]);
            }

            $evidence->update([
                'verified_by_user_id' => $request->user()->id,
                'verification_status' => 'VERIFIED',
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);

            $job->update([
                'worker_name' => $verificationData['worker_name'],
                'worker_received_at' => $verificationData['worker_received_at'],
                'worker_completed_at' => $verificationData['worker_completed_at'],
                'worker_remarks' => $verificationData['worker_remarks'] ?? null,
                'form_verified_by_user_id' => $request->user()->id,
                'form_verified_at' => now(),
            ]);

            $audit->record(
                'LAUNDRY_FORM_VERIFIED_AND_ENCODED',
                $job,
                after: [
                    'verified_by_user_id' => $request->user()->id,
                    'evidence_submission_id' => $evidence->id,
                ]
            );

            $notifications->send(
                'LAUNDRY_FORM_VERIFIED',
                collect([$job->custody->borrower]),
                "SPMU verified the accomplished Laundry Form for {$job->custody->custody_no}. After collecting the cleaned linen, bring it to SPMU for final physical inspection.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return back()->with(
            'status',
            $decisionData['decision'] === 'VERIFIED'
                ? 'Laundry Form verified and encoded from the scanned physical form.'
                : 'Replacement Laundry Form scan requested from Laundry.'
        );
    }

    private function authorizeLaundryWorker(Request $request): void
    {
        abort_unless(
            $request->user()->access_classification === AccessClassification::LaundryWorker,
            403
        );
    }

    private function currentLaundryDocument(LaundryJob $job): GeneratedDocument
    {
        $document = GeneratedDocument::query()
            ->where('subject_type', \App\Models\CustodyTransaction::class)
            ->where('subject_id', $job->custody_transaction_id)
            ->where('document_type', 'LAUNDRY_FORM')
            ->where('status', 'FINAL')
            ->latest('id')
            ->first();

        if (! $document) {
            throw ValidationException::withMessages([
                'evidence' =>
                    'The current Laundry Form is unavailable. Ask SPMU to regenerate the physical form before uploading.',
            ]);
        }

        return $document;
    }

    private function spmuRecipients()
    {
        return User::query()
            ->whereIn(
                'access_classification',
                [
                    AccessClassification::SpmuHead->value,
                    AccessClassification::SpmuOfficer->value,
                ]
            )
            ->where('account_status', 'ACTIVE')
            ->get();
    }

    private function laundryRecipients()
    {
        return User::query()
            ->where(
                'access_classification',
                AccessClassification::LaundryWorker->value
            )
            ->where('account_status', 'ACTIVE')
            ->get();
    }
}
