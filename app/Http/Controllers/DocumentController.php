<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\Payment;
use App\Models\StoredFile;
use App\Services\ProtectedFileService;
use App\Services\RequestWorkflowService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function download(Request $request, GeneratedDocument $document, ProtectedFileService $files, RequestWorkflowService $workflow): StreamedResponse|BinaryFileResponse
    {
        $document->loadMissing(['file', 'version.request']);
        $borrowingRequest = $document->version?->request;
        $user = $request->user();
        $billingBorrowerId = $document->subject_type === BillingStatement::class
            ? BillingStatement::whereKey($document->subject_id)->value('borrower_user_id')
            : null;
        abort_unless(
            ($borrowingRequest && $borrowingRequest->borrower_user_id === $user->id)
            || (int) $billingBorrowerId === $user->id
            || $user->hasRole(UserRole::Spmu)
            || $user->hasRole(UserRole::Gsu)
            || $user->hasRole(UserRole::Vpaf)
            || $user->hasRole(UserRole::Ictu),
            403,
        );
        abort_if(in_array($document->status, ['INVALIDATED', 'EXPIRED'], true), 410, 'This controlled document is no longer valid.');

        if ($document->document_type === 'APPROVED_REQUEST_LETTER' && $borrowingRequest && $borrowingRequest->borrower_user_id === $user->id) {
            $workflow->recordApprovedLetterDownload($borrowingRequest, $document, $user, $request->ip(), $request->userAgent());
        }

        $bytes = $files->bytes($document->file);

        return response()->streamDownload(fn () => print $bytes, $document->file->original_name, ['Content-Type' => $document->file->mime_type]);
    }

    public function protectedFile(Request $request, StoredFile $file, ProtectedFileService $files): StreamedResponse
    {
        $user = $request->user();
        $operationalEvidence = in_array($file->classification, ['PAPER_EVIDENCE', 'PAYMENT_EVIDENCE', 'INCIDENT_EVIDENCE'], true);
        $belongsToBorrower = EvidenceSubmission::query()->where('stored_file_id', $file->id)->where('borrower_user_id', $user->id)->exists()
            || Incident::query()->where('supporting_evidence_file_id', $file->id)->where('borrower_user_id', $user->id)->exists()
            || Payment::query()->where('evidence_file_id', $file->id)->whereHas('billingStatement', fn ($query) => $query->where('borrower_user_id', $user->id))->exists();
        abort_unless(
            $file->uploaded_by_user_id === $user->id
            || $belongsToBorrower
            || ($operationalEvidence && $user->hasRole(UserRole::Spmu)),
            403,
        );

        return response()->stream(fn () => print $files->bytes($file), 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
