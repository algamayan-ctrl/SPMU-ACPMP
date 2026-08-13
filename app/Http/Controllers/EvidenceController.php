<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\LaundryRecord;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EvidenceController extends Controller
{
    public function store(Request $request, GeneratedDocument $document, ProtectedFileService $files, AuditService $audit, NotificationService $notifications): RedirectResponse
    {
        abort_unless(in_array($document->document_type, ['GATE_PASS', 'LAUNDRY_FORM'], true), 404);
        $document->loadMissing('version.request.borrower');
        $borrower = $document->version?->request?->borrower;
        abort_unless($borrower && ($request->user()->id === $borrower->id || $request->user()->hasRole(UserRole::Spmu)), 403);
        $fallback = $request->user()->id !== $borrower->id;
        $maxKb = ((int) SystemSetting::value('max_upload_mb', 5)) * 1024;
        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:'.$maxKb],
            'fallback_reason' => [Rule::requiredIf($fallback), 'nullable', 'string', 'max:1000'],
        ]);
        $file = $files->storeUpload($data['evidence'], 'paper-evidence', 'PAPER_EVIDENCE');
        $submission = EvidenceSubmission::query()->create([
            'generated_document_id' => $document->id,
            'stored_file_id' => $file->id,
            'borrower_user_id' => $borrower->id,
            'uploaded_by_user_id' => $request->user()->id,
            'upload_mode' => $fallback ? 'SPMU_FALLBACK' : 'BORROWER_PRIMARY',
            'fallback_reason' => $data['fallback_reason'] ?? null,
            'borrower_notified_at' => $fallback ? now() : null,
            'submitted_at' => now(),
            'verification_status' => 'PENDING_VERIFICATION',
        ]);
        $audit->record('PAPER_EVIDENCE_UPLOADED', $submission, reason: $data['fallback_reason'] ?? null, after: ['mode' => $submission->upload_mode, 'sha256' => $file->sha256]);
        if ($fallback) {
            $notifications->send('SPMU_FALLBACK_UPLOAD', collect([$borrower]), "SPMU uploaded {$document->document_type} evidence on your behalf. It remains pending separate verification.", $document, ['SYSTEM', 'EMAIL']);
        }

        return back()->with('status', 'Evidence uploaded and marked Pending Verification. Upload alone does not close the transaction.');
    }

    public function verify(Request $request, EvidenceSubmission $evidence, AuditService $audit, NotificationService $notifications): RedirectResponse
    {
        abort_unless($request->user()->hasRole(UserRole::Spmu), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['VERIFIED', 'REJECTED'])], 'reason' => ['nullable', 'string', 'max:1000']]);
        if ($evidence->uploaded_by_user_id === $request->user()->id && $evidence->upload_mode === 'SPMU_FALLBACK') {
            throw ValidationException::withMessages(['decision' => 'The SPMU fallback uploader cannot verify the same evidence. A separate SPMU verifier is required.']);
        }
        if ($data['decision'] === 'REJECTED' && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }
        $evidence->update([
            'verified_by_user_id' => $request->user()->id,
            'verification_status' => $data['decision'],
            'verified_at' => now(),
            'rejection_reason' => $data['decision'] === 'REJECTED' ? $data['reason'] : null,
        ]);
        $evidence->loadMissing('document');
        if ($data['decision'] === 'VERIFIED' && $evidence->document->document_type === 'LAUNDRY_FORM') {
            LaundryRecord::query()
                ->whereHas('returnLine.custodyLine.custody', fn ($query) => $query->where('id', $evidence->document->subject_id))
                ->whereNot('status', 'VERIFIED')
                ->update(['status' => 'EVIDENCE_VERIFIED_PENDING_PHYSICAL_CHECK']);
        }
        $audit->record('PAPER_EVIDENCE_'.$data['decision'], $evidence, reason: $data['reason'] ?? null);
        $notifications->send('EVIDENCE_'.$data['decision'], collect([$evidence->document->version->request->borrower]), "{$evidence->document->document_type} evidence was {$data['decision']}. ".($data['reason'] ?? ''), $evidence->document, ['SYSTEM', 'EMAIL']);

        return back()->with('status', 'Evidence decision recorded separately from physical verification.');
    }
}
