<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\BorrowingRequest;
use App\Models\RequestSupportingDocument;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequestSupportingDocumentController extends Controller
{
    public function store(
        Request $request,
        BorrowingRequest $borrowingRequest,
        ProtectedFileService $files,
        AuditService $audit
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($borrowingRequest->borrower_user_id === $user->id, 403);
        abort_unless(
            in_array(
                $borrowingRequest->status,
                [RequestStatus::Draft, RequestStatus::ReturnedForRevision],
                true
            ),
            403
        );

        $maxKb = ((int) SystemSetting::value('max_upload_mb', 5)) * 1024;

        $data = $request->validate([
            'document_type' => [
                'required',
                Rule::in([
                    RequestSupportingDocument::TYPE_SIGNED_REQUEST_LETTER,
                    RequestSupportingDocument::TYPE_PTC,
                ]),
            ],
            'document' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:'.$maxKb,
            ],
        ]);

        $borrowingRequest->loadMissing('currentVersion');
        $version = $borrowingRequest->currentVersion;

        if (! $version) {
            throw ValidationException::withMessages([
                'document' => 'A current request version is required before uploading supporting documents.',
            ]);
        }

        $storedFile = $files->storeUpload(
            $data['document'],
            'request-supporting-documents/'.$borrowingRequest->id,
            'PROTECTED'
        );

        $supportingDocument = DB::transaction(function () use (
            $version,
            $storedFile,
            $user,
            $data
        ): RequestSupportingDocument {
            RequestSupportingDocument::query()
                ->where('request_version_id', $version->id)
                ->where('document_type', $data['document_type'])
                ->where('status', RequestSupportingDocument::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->each(fn (RequestSupportingDocument $document) => $document->update([
                    'status' => RequestSupportingDocument::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ]));

            return RequestSupportingDocument::query()->create([
                'request_version_id' => $version->id,
                'stored_file_id' => $storedFile->id,
                'uploaded_by_user_id' => $user->id,
                'document_type' => $data['document_type'],
                'status' => RequestSupportingDocument::STATUS_ACTIVE,
                'uploaded_at' => now(),
            ]);
        }, 3);

        $audit->record(
            'REQUEST_SUPPORTING_DOCUMENT_UPLOADED',
            $supportingDocument,
            after: [
                'request_id' => $borrowingRequest->id,
                'request_version_id' => $version->id,
                'document_type' => $supportingDocument->document_type,
                'sha256' => $storedFile->sha256,
            ]
        );

        return back()->with(
            'status',
            $supportingDocument->label().' uploaded successfully.'
        );
    }

    public function download(
        Request $request,
        RequestSupportingDocument $supportingDocument,
        ProtectedFileService $files
    ): StreamedResponse {
        $supportingDocument->loadMissing('version.request', 'file');

        $borrowingRequest = $supportingDocument->version?->request;
        abort_unless($borrowingRequest, 404);

        $user = $request->user();
        $allowed = $borrowingRequest->borrower_user_id === $user->id
            || $user->hasRole(UserRole::Spmu);

        abort_unless($allowed, 403);

        $file = $supportingDocument->file;
        abort_unless($file, 404);

        return response()->streamDownload(
            function () use ($files, $file): void {
                echo $files->bytes($file);
            },
            $file->original_name,
            [
                'Content-Type' => $file->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
