@extends('layouts.app', ['title' => 'Request '.$borrowingRequest->request_no])
@section('content')
@php
    $v = $borrowingRequest->currentVersion;
    $workspace = session('active_workspace');
    $isBorrower = $workspace === 'BORROWER';
    $isSpmu = $workspace === 'SPMU';
    $currentDocs = $v->supportingDocuments->where('is_current', true);
    $requestLetterDoc = $currentDocs->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
    );
    $permissionToConductDoc = $currentDocs->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
    );
    $draftRequestLetter = $v->documents
        ->where('document_type', 'REQUEST_LETTER')
        ->where('status', 'DRAFT')
        ->sortByDesc('id')
        ->first();
    $pendingCancellation = $borrowingRequest->pendingCancellation;
    $isUnderSpmuReview = $isSpmu
        && $borrowingRequest->status === App\Enums\RequestStatus::UnderSpmu;
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Borrowing request</p>
        <h1>{{ $borrowingRequest->request_no }}</h1>
        <p>{{ $v->purpose_event }}</p>
    </div>
    <x-status-badge :status="$borrowingRequest->status->value" />
</section>

@if($errors->any())
<section class="content-area">
    <div class="callout danger">
        <strong>Please review:</strong>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
</section>
@endif

@if($isUnderSpmuReview)
<section class="content-area spmu-verification-workspace" data-spmu-verification-workspace>
        {{-- SPMU REVIEW CONTEXT STRIP --}}
    <div class="spmu-review-context">

        <div class="spmu-review-context-item">
            <span>Borrower</span>
            <strong>{{ $borrowingRequest->borrower->full_name }}</strong>
        </div>

        <div class="spmu-review-context-item">
            <span>Purpose / Event</span>
            <strong>{{ $v->purpose_event }}</strong>
        </div>

        <div class="spmu-review-context-item">
            <span>Schedule</span>
            <strong>
                {{ optional($v->schedule_date ?: $v->needed_from)->format('d M Y') }}
                →
                {{ optional($v->return_date ?: $v->return_due_at)->format('d M Y') }}
            </strong>
        </div>

        <div class="spmu-review-context-item">
            <span>Request Type</span>
            <strong>
                {{ $v->represents_student_activity
                    ? 'Student Activity'
                    : 'Regular Borrowing' }}
            </strong>
        </div>

    </div>
<div class="spmu-verification-grid">
        <x-document-review-viewer
            :file="$requestLetterDoc?->file"
            title="Inspect the approved document"
        />
<article class="card spmu-checklist-panel">
            <div class="card-header">
                <div>
                    <p class="eyebrow">SPMU verification</p>
                    <h2>Document checklist</h2>
                </div>
            </div>

            <p class="meta">
                Inspect the actual scanned letter on the left, then confirm only what is visibly present in the document.
            </p>

            @if($v->represents_student_activity)
                <div class="spmu-supporting-document">
                    <div>
                        <strong>Permission to Conduct Letter</strong>
                        <small>Required because Student Activity / Organization is selected.</small>
                    </div>

                    @if($permissionToConductDoc)
                        <a
                            class="button secondary small ui-pressable"
                            href="{{ route('files.show', $permissionToConductDoc->file, false) }}"
                            target="_blank"
                            rel="noopener"
                        >
                            View Attachment
                        </a>
                    @else
                        <span class="status-badge status-danger">Missing</span>
                    @endif
                </div>
            @endif

            @if($canDecide)
                <form
                    method="post"
                    action="{{ route('approvals.decide', $borrowingRequest) }}"
                    class="spmu-verification-form"
                    data-verification-form
                    data-required-supporting-present="{{ (!$v->represents_student_activity || $permissionToConductDoc) && $requestLetterDoc ? '1' : '0' }}"
                >
                    @csrf

                    <input
                        type="hidden"
                        name="decision"
                        value=""
                        data-verification-decision
                    >

                    <div class="spmu-checklist">
                        <label class="spmu-check-row">
                            <input
                                type="checkbox"
                                name="details_complete"
                                value="1"
                                @checked(old('details_complete'))
                                data-verification-check
                            >
                            <span>
                                <strong>Required details are complete</strong>
                                <small>Borrower, event, dates, location, items, and quantities match the approved request.</small>
                            </span>
                        </label>

                        <label class="spmu-check-row">
                            <input
                                type="checkbox"
                                name="signatures_present"
                                value="1"
                                @checked(old('signatures_present'))
                                data-verification-check
                            >
                            <span>
                                <strong>Required signatures are present</strong>
                                <small>Required handwritten signatures / endorsements are visible on the scanned letter.</small>
                            </span>
                        </label>

                        <label class="spmu-check-row">
                            <input
                                type="checkbox"
                                name="document_readable"
                                value="1"
                                @checked(old('document_readable'))
                                data-verification-check
                            >
                            <span>
                                <strong>Document is clear and readable</strong>
                                <small>The uploaded scan is legible enough to verify the approved details.</small>
                            </span>
                        </label>
                    </div>

                    <div class="spmu-document-status" aria-live="polite">
                        <span>Document Status</span>
                        <strong
                            class="status-badge status-warning"
                            data-document-status
                        >Incomplete</strong>
                    </div>

                    <div
                        class="spmu-remarks"
                        data-verification-remarks-wrap
                        @if(!old('remarks')) hidden @endif
                    >
                        <label for="verification-remarks">
                            <strong>Remarks</strong>
                            <small data-verification-remarks-help>
                                Required when returning for revision or rejecting the request.
                            </small>
                        </label>
                        <textarea
                            id="verification-remarks"
                            name="remarks"
                            rows="4"
                            maxlength="2000"
                            placeholder="Enter the exact reason the borrower needs to address."
                            data-verification-remarks
                        >{{ old('remarks') }}</textarea>
                        <p
                            class="field-error"
                            data-verification-inline-error
                            hidden
                        ></p>
                    </div>

                    <div class="spmu-decision-actions">
                        <button
                            type="button"
                            class="button primary ui-pressable"
                            data-decision-trigger="APPROVED"
                            data-approve-button
                            disabled
                        >
                            Verify & Approve
                        </button>

                        <button
                            type="button"
                            class="button secondary ui-pressable"
                            data-decision-trigger="RETURNED_FOR_REVISION"
                        >
                            Return for Revision
                        </button>

                        <button
                            type="button"
                            class="button danger ui-pressable"
                            data-decision-trigger="REJECTED"
                        >
                            Reject
                        </button>
                    </div>


                </form>
            @else
                <div class="callout neutral">
                    <strong>Review only.</strong>
                    Decision controls are available to the SPMU Head or to an SPMU Action Officer with an active formal delegation.
                </div>
            @endif
        </article>
    </div>
</section>

@if($canDecide)
<dialog
    class="spmu-confirm-dialog"
    data-verification-confirm-dialog
    aria-labelledby="spmu-confirm-title"
>
    <form method="dialog" class="spmu-confirm-dialog__surface">
        <div class="spmu-confirm-dialog__icon" aria-hidden="true">!</div>
        <div class="spmu-confirm-dialog__copy">
            <p class="eyebrow spmu-confirm-dialog__eyebrow">Confirm decision</p>
            <h2 id="spmu-confirm-title" data-confirm-title>Are you sure?</h2>
            <p class="spmu-confirm-dialog__message" data-confirm-message>
                Review the decision before continuing.
            </p>
        </div>

        <div class="spmu-confirm-dialog__actions">
            <button
                type="button"
                class="button secondary ui-pressable"
                data-confirm-cancel
            >
                No, go back
            </button>

            <button
                type="button"
                class="button primary ui-pressable"
                data-confirm-submit
            >
                Yes, continue
            </button>
        </div>
    </form>
</dialog>
@endif
@endif

<section class="content-grid two {{ $isUnderSpmuReview ? 'spmu-request-summary-grid' : '' }}">
    <article class="card {{ $isUnderSpmuReview ? 'spmu-request-summary-card' : '' }}">
        <div class="card-header">
            <div>
                <p class="eyebrow">Request details</p>
                <h2>{{ $isUnderSpmuReview ? 'Request summary' : 'Borrowing information' }}</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Borrower</dt>
            <dd>{{ $borrowingRequest->borrower->full_name }}</dd>

            <dt>Office / Department</dt>
            <dd>{{ $borrowingRequest->borrower->organizationalUnit?->unit_name ?: '—' }}</dd>

            <dt>Purpose / Event</dt>
            <dd>{{ $v->purpose_event }}</dd>

            <dt>Location</dt>
            <dd>{{ $v->location }}</dd>

            <dt>Schedule Date</dt>
            <dd>{{ optional($v->schedule_date ?: $v->needed_from)->format('d F Y') }}</dd>

            <dt>Expected Return Date</dt>
            <dd>{{ optional($v->return_date ?: $v->return_due_at)->format('d F Y') }}</dd>

            <dt>Student Activity</dt>
            <dd>{{ $v->represents_student_activity ? 'Yes' : 'No' }}</dd>
        </dl>
    </article>

    @unless($isUnderSpmuReview)
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Document verification</p>
                <h2>Uploaded scanned documents</h2>
            </div>
        </div>

                @forelse($currentDocs as $doc)
            @php
                $verificationLabel = match($doc->verification_status ?? $doc->status) {
                    App\Models\RequestSupportingDocument::STATUS_VERIFIED => 'Verified',
                    App\Models\RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION => 'Returned for Revision',
                    App\Models\RequestSupportingDocument::STATUS_REJECTED => 'Rejected',
                    default => 'Pending Verification',
                };
            @endphp

            <div class="request-scan-row">
                <div class="request-scan-main">
                    <strong class="request-scan-title">
                        {{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                            ? 'Approved Borrowing Request Letter'
                            : 'Permission to Conduct Letter' }}
                    </strong>

                    <div class="request-scan-meta">
                        <span class="request-scan-version">Version {{ $doc->version_no ?? 1 }}</span>
                        <span class="request-scan-dot" aria-hidden="true">•</span>
                        <span class="request-scan-status">{{ $verificationLabel }}</span>
                    </div>
                </div>

                <a
                    class="button primary small ui-pressable request-scan-button"
                    href="{{ route('files.show', $doc->file, false) }}"
                    target="_blank"
                    rel="noopener"
                >
                    View Scan
                </a>
            </div>
        @empty
            <div class="empty-state">
                <strong>No current scanned supporting document.</strong>
            </div>
        @endforelse

        <p class="meta request-scan-note">
            The uploaded letter is evidence/notice of the institutionally approved request.
            It does not reserve inventory until SPMU verifies and approves it in the system.
        </p>
    </article>
    @endunless
</section>

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Requested property</p>
                <h2>Items and quantities</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Requested</th>
                        <th>Approved / Reserved</th>
                        <th>Use</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($v->items as $item)
                    <tr>
                        <td>{{ $item->description_snapshot }}</td>
                        <td>{{ $item->requested_quantity + 0 }} {{ $item->unit_snapshot }}</td>
                        <td>
                            {{ $item->approved_quantity === null
                                ? 'Not reserved yet'
                                : ($item->approved_quantity + 0).' '.$item->unit_snapshot }}
                        </td>
                        <td>{{ str($item->use_location)->replace('_',' ')->title() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>

@if($isBorrower && in_array($borrowingRequest->status, [App\Enums\RequestStatus::Draft, App\Enums\RequestStatus::ReturnedForRevision], true))

@php
    /*
     * BORROWER WORKFLOW TRACKING STATE
     *
     * Step completion is based on current uploaded supporting
     * documents. Physical printing/signing cannot be detected
     * independently by the system.
     */

    $borrowerCurrentSupportingDocuments =
        $v->supportingDocuments()
            ->where('is_current', true)
            ->get();

    $borrowerHasSignedLetter =
        $borrowerCurrentSupportingDocuments
            ->contains(
                fn ($document) =>
                    $document->document_type
                    === \App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
            );

    $borrowerHasPtc =
        $borrowerCurrentSupportingDocuments
            ->contains(
                fn ($document) =>
                    $document->document_type
                    === \App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
            );

    $borrowerStep1Complete =
        $borrowerHasSignedLetter;

    $borrowerStep2Complete =
        $borrowerHasSignedLetter
        && (
            ! $v->represents_student_activity
            || $borrowerHasPtc
        );

    $borrowerStep3Ready =
        $borrowerStep2Complete;
@endphp

<style>
/* ============================================================
   Borrower Request Completion Workflow
   Scoped only to this borrower workflow.
   ============================================================ */

.borrower-completion-workflow {
    overflow: hidden;
}

.borrower-completion-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 18px;
}

.borrower-completion-header h2 {
    margin: 2px 0 6px;
}

.borrower-completion-header .meta {
    max-width: 680px;
    margin: 0;
}


/* ------------------------------------------------------------
   3-step indicator
   ------------------------------------------------------------ */

.borrower-completion-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    list-style: none;
    margin: 0 0 28px;
    padding: 0;
    border: 1px solid #dce4ee;
    border-radius: 10px;
    background: #f8fafc;
    overflow: hidden;
}

.borrower-completion-steps li {
    position: relative;
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
    padding: 14px 18px;
}

.borrower-completion-steps li + li {
    border-left: 1px solid #dce4ee;
}

.borrower-completion-steps li:not(:last-child)::after {
    content: "›";
    position: absolute;
    right: -7px;
    z-index: 2;
    width: 14px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8fafc;
    color: #8b9aaf;
    font-size: 19px;
}

.borrower-completion-step-number {
    flex: 0 0 30px;
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #c8d3e0;
    border-radius: 999px;
    background: #eef2f7;
    color: #56677d;
    font-size: 12px;
    font-weight: 800;
}

.borrower-completion-steps strong,
.borrower-completion-steps small {
    display: block;
}

.borrower-completion-steps strong {
    color: #10233d;
    font-size: 13px;
    line-height: 1.3;
}

.borrower-completion-steps small {
    margin-top: 2px;
    color: #718096;
    font-size: 11px;
    line-height: 1.35;
}

/* BORROWER TRACKING COLORS */

.borrower-completion-steps li {
    transition:
        background-color 160ms ease,
        border-color 160ms ease;
}

.borrower-completion-steps li.is-pending {
    background: #f8fafc;
}

.borrower-completion-steps li.is-current {
    background: #eef5ff;
}

.borrower-completion-steps li.is-current .borrower-completion-step-number {
    border-color: #1769e0;
    background: #1769e0;
    color: #fff;
}

.borrower-completion-steps li.is-current strong {
    color: #0b4ea8;
}

.borrower-completion-steps li.is-complete {
    background: #eef9f3;
}

.borrower-completion-steps li.is-complete .borrower-completion-step-number {
    border-color: #23855b;
    background: #23855b;
    color: #fff;
}

.borrower-completion-steps li.is-complete strong {
    color: #176b49;
}

.borrower-completion-steps li.is-complete small {
    color: #23855b;
    font-weight: 700;
}

.borrower-completion-steps li.is-ready {
    background: #eef5ff;
}

.borrower-completion-steps li.is-ready .borrower-completion-step-number {
    border-color: #1769e0;
    background: #1769e0;
    color: #fff;
}

.borrower-completion-steps li.is-ready strong,
.borrower-completion-steps li.is-ready small {
    color: #0b4ea8;
}

.borrower-completion-steps li.is-ready small {
    font-weight: 700;
}


/* ------------------------------------------------------------
   Individual steps
   ------------------------------------------------------------ */

.borrower-completion-step {
    padding: 2px 0;
}

.borrower-completion-step-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}

.borrower-completion-step-heading span {
    color: #1769e0;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .09em;
    text-transform: uppercase;
}

.borrower-completion-step-heading h3 {
    margin: 0;
    color: #10233d;
    font-size: 17px;
}

.borrower-completion-copy {
    max-width: 820px;
    margin: 0 0 14px;
    color: #43546a;
    line-height: 1.55;
}

.borrower-completion-divider {
    height: 1px;
    margin: 24px 0;
    background: #e3e9f0;
}

.borrower-physical-signature-note,
.borrower-reservation-note {
    display: block;
    margin: 10px 0 0;
    color: #718096;
}


/* ------------------------------------------------------------
   Upload section
   ------------------------------------------------------------ */

.borrower-completion-upload-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 14px 18px;
    align-items: end;
    margin-top: 14px;
}

.borrower-completion-upload-form > .field-error {
    grid-column: 1 / -1;
    margin: 0;
}

.borrower-upload-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    min-width: 0;
}

.borrower-upload-field {
    display: block;
    min-width: 0;
    margin: 0;
}

.borrower-upload-label {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 7px;
    color: #1e3048;
    font-weight: 700;
}

.borrower-required-mark {
    color: #c43d3d;
}

.borrower-upload-field input[type="file"] {
    width: 100%;
    min-height: 42px;
}

.borrower-upload-field .meta {
    display: block;
    margin-top: 6px;
    line-height: 1.4;
}

.borrower-upload-actions {
    display: flex;
    align-items: flex-start;
    justify-content: flex-start;
    padding-top: 27px;
}

.borrower-upload-actions .button {
    white-space: nowrap;
}


/* ------------------------------------------------------------
   Submit section
   ------------------------------------------------------------ */

.borrower-submit-actions {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 10px;
    margin-top: 18px;
}

.borrower-submit-actions form {
    margin: 0;
}


/* ------------------------------------------------------------
   Responsive
   ------------------------------------------------------------ */

@media (max-width: 760px) {

    .borrower-completion-steps {
        grid-template-columns: 1fr;
    }

    .borrower-completion-steps li + li {
        border-left: 0;
        border-top: 1px solid #dce4ee;
    }

    .borrower-completion-steps li:not(:last-child)::after {
        display: none;
    }

    .borrower-completion-upload-form {
        grid-template-columns: 1fr;
    }

    .borrower-upload-fields {
        grid-template-columns: 1fr;
    }

    .borrower-upload-actions,
    .borrower-upload-actions .button {
        width: 100%;
    }

    .borrower-submit-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .borrower-submit-actions > *,
    .borrower-submit-actions form,
    .borrower-submit-actions .button {
        width: 100%;
    }
}


/* ------------------------------------------------------------
   Dark theme
   ------------------------------------------------------------ */

html[data-theme="dark"] .borrower-completion-steps {
    background: rgba(255,255,255,.035);
    border-color: rgba(255,255,255,.11);
}

html[data-theme="dark"] .borrower-completion-steps li + li {
    border-color: rgba(255,255,255,.11);
}

html[data-theme="dark"] .borrower-completion-steps li:not(:last-child)::after {
    background: #152033;
}

html[data-theme="dark"] .borrower-completion-steps strong,
html[data-theme="dark"] .borrower-completion-step-heading h3,
html[data-theme="dark"] .borrower-upload-label {
    color: #edf4ff;
}

html[data-theme="dark"] .borrower-completion-copy {
    color: #c8d3e1;
}

html[data-theme="dark"] .borrower-completion-divider {
    background: rgba(255,255,255,.10);
}
</style>


<section class="content-area borrower-completion-area">

    <article class="card borrower-completion-workflow">

        {{-- =====================================================
             HEADER
        ====================================================== --}}

        <div class="borrower-completion-header">

            <div>
                <p class="eyebrow">Next steps</p>

                <h2>Complete Your Request</h2>

                <p class="meta">
                    Complete the required physical document and upload it before
                    sending the request to SPMU for review.
                </p>
            </div>

        </div>


        {{-- =====================================================
             PROGRESS INDICATOR
        ====================================================== --}}

        <ol
            class="borrower-completion-steps"
            aria-label="Borrowing request completion steps"
        >

            <li class="{{ $borrowerStep1Complete ? 'is-complete' : 'is-current' }}">
    <span class="borrower-completion-step-number">
        {{ $borrowerStep1Complete ? '✓' : '1' }}
    </span>

    <span>
        <strong>Print &amp; Sign</strong>
        <small>
            {{ $borrowerStep1Complete ? 'Completed' : 'Prepare and sign the physical letter' }}
        </small>
    </span>
</li>

            <li class="{{ $borrowerStep2Complete ? 'is-complete' : ($borrowerStep1Complete ? 'is-current' : 'is-pending') }}">
    <span class="borrower-completion-step-number">
        {{ $borrowerStep2Complete ? '✓' : '2' }}
    </span>

    <span>
        <strong>Upload Signed Copy</strong>
        <small>
            {{ $borrowerStep2Complete ? 'Completed' : 'Attach required documents' }}
        </small>
    </span>
</li>

            <li class="{{ $borrowerStep3Ready ? 'is-ready' : 'is-pending' }}">
    <span class="borrower-completion-step-number">
        3
    </span>

    <span>
        <strong>Submit to SPMU</strong>
        <small>
            {{ $borrowerStep3Ready ? 'Ready to submit' : 'Send the request for review' }}
        </small>
    </span>
</li>

        </ol>


        {{-- =====================================================
             STEP 1 — PRINT & SIGN
        ====================================================== --}}

        <div class="borrower-completion-step">

            <div class="borrower-completion-step-heading">
                <span>Step 1</span>
                <h3>Print &amp; Sign</h3>
            </div>

            <p class="borrower-completion-copy">
                Print the system-generated Borrowing Request Letter and obtain
                the required handwritten/wet signatures.
            </p>

            <div class="inline-actions">

                @if($draftRequestLetter)

                    <a
                        class="button primary ui-pressable"
                        href="{{ route('documents.download', $draftRequestLetter) }}"
                        target="_blank"
                        rel="noopener"
                    >
                        View / Print Request Letter
                    </a>

                @else

                    <form
                        method="post"
                        action="{{ route('requests.recover-draft-document', $borrowingRequest) }}"
                    >
                        @csrf

                        <button class="button primary ui-pressable">
                            Generate Request Letter
                        </button>
                    </form>

                @endif

            </div>

            <small class="borrower-physical-signature-note">
                Required signatures are completed physically. Electronic
                signatures are not used.
            </small>

        </div>


        <div
            class="borrower-completion-divider"
            aria-hidden="true"
        ></div>


        {{-- =====================================================
             STEP 2 — UPLOAD
        ====================================================== --}}

        <div class="borrower-completion-step">

            <div class="borrower-completion-step-heading">
                <span>Step 2</span>
                <h3>Upload Signed Document</h3>
            </div>

            <p class="borrower-completion-copy">
                Upload the fully accomplished scanned copy before submitting
                the request to SPMU.
            </p>


            @php
    /*
     * STEP 2 DOCUMENT DISPLAY STATE
     */

    $borrowerSignedLetterDocument =
        $currentDocs->first(
            fn ($document) =>
                $document->document_type
                === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
        );

    $borrowerPtcDocument =
        $currentDocs->first(
            fn ($document) =>
                $document->document_type
                === App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
        );

    $borrowerDocumentStatusLabel =
        static function ($document): string {
            $status =
                $document->verification_status
                ?? $document->status
                ?? App\Models\RequestSupportingDocument::STATUS_PENDING;

            return match ($status) {
                App\Models\RequestSupportingDocument::STATUS_VERIFIED =>
                    'Verified',

                App\Models\RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION =>
                    'Returned for Revision',

                App\Models\RequestSupportingDocument::STATUS_REJECTED =>
                    'Rejected',

                default =>
                    'Pending Verification',
            };
        };

    $borrowerDocumentDisplayName =
        static function ($document): string {
            $file = $document?->file;

            if (! $file) {
                return 'Uploaded document';
            }

            return
                $file->original_name
                ?? $file->original_filename
                ?? $file->filename
                ?? $file->name
                ?? 'Uploaded document';
        };

    $borrowerRequiredUploadsComplete =
        (bool) $borrowerSignedLetterDocument
        && (
            ! $v->represents_student_activity
            || (bool) $borrowerPtcDocument
        );
@endphp


<form
    method="post"
    action="{{ route('requests.supporting-documents.store', $borrowingRequest) }}"
    enctype="multipart/form-data"
    class="borrower-completion-upload-form borrower-stateful-upload-form"
>
    @csrf

    @error('documents')
        <p class="field-error">
            {{ $message }}
        </p>
    @enderror


    <div class="borrower-upload-fields borrower-upload-fields--stateful">

        {{-- =====================================================
             SIGNED BORROWING REQUEST LETTER
        ====================================================== --}}

        <div class="borrower-document-slot">

            @if($borrowerSignedLetterDocument)

                <div
                    class="borrower-document-uploaded"
                    data-uploaded-state
                >
                    <div class="borrower-document-uploaded-main">

                        <span
                            class="borrower-document-check"
                            aria-hidden="true"
                        >
                            ✓
                        </span>

                        <div class="borrower-document-uploaded-info">

                            <strong>
                                Signed Borrowing Request Letter
                            </strong>

                            <span class="borrower-document-file-name">
                                {{ $borrowerDocumentDisplayName($borrowerSignedLetterDocument) }}
                            </span>

                            <div class="borrower-document-meta">

                                <span>
                                    Version {{ $borrowerSignedLetterDocument->version_no ?? 1 }}
                                </span>

                                <span aria-hidden="true">•</span>

                                <span class="borrower-document-status">
                                    {{ $borrowerDocumentStatusLabel($borrowerSignedLetterDocument) }}
                                </span>

                            </div>

                        </div>

                    </div>


                    <div class="borrower-document-card-actions">

                        <a
                            class="button secondary small ui-pressable"
                            href="{{ route('files.show', $borrowerSignedLetterDocument->file, false) }}"
                            target="_blank"
                            rel="noopener"
                        >
                            View Scan
                        </a>

                        <button
                            class="button secondary small ui-pressable borrower-replace-document"
                            type="button"
                        >
                            Replace
                        </button>

                    </div>

                </div>

            @endif


            <label
                class="borrower-upload-field borrower-document-picker"
                data-picker-state
                @if($borrowerSignedLetterDocument) hidden @endif
            >

                <span class="borrower-upload-label">
                    Scanned Fully Signed Borrowing Request Letter

                    <span
                        class="borrower-required-mark"
                        aria-hidden="true"
                    >*</span>
                </span>

                <input
                    type="file"
                    name="approved_request_letter"
                    accept=".pdf,.png,.jpg,.jpeg,.webp"
                >

                <small class="meta">
                    PDF, PNG, JPG, JPEG or WebP &middot; Maximum 10 MB
                </small>

                @error('approved_request_letter')
                    <small class="field-error">
                        {{ $message }}
                    </small>
                @enderror

            </label>

        </div>


        {{-- =====================================================
             PERMISSION TO CONDUCT LETTER
        ====================================================== --}}

        @if($v->represents_student_activity)

            <div class="borrower-document-slot">

                @if($borrowerPtcDocument)

                    <div
                        class="borrower-document-uploaded"
                        data-uploaded-state
                    >

                        <div class="borrower-document-uploaded-main">

                            <span
                                class="borrower-document-check"
                                aria-hidden="true"
                            >
                                ✓
                            </span>

                            <div class="borrower-document-uploaded-info">

                                <strong>
                                    Permission to Conduct Letter (PTC)
                                </strong>

                                <span class="borrower-document-file-name">
                                    {{ $borrowerDocumentDisplayName($borrowerPtcDocument) }}
                                </span>

                                <div class="borrower-document-meta">

                                    <span>
                                        Version {{ $borrowerPtcDocument->version_no ?? 1 }}
                                    </span>

                                    <span aria-hidden="true">•</span>

                                    <span class="borrower-document-status">
                                        {{ $borrowerDocumentStatusLabel($borrowerPtcDocument) }}
                                    </span>

                                </div>

                            </div>

                        </div>


                        <div class="borrower-document-card-actions">

                            <a
                                class="button secondary small ui-pressable"
                                href="{{ route('files.show', $borrowerPtcDocument->file, false) }}"
                                target="_blank"
                                rel="noopener"
                            >
                                View Scan
                            </a>

                            <button
                                class="button secondary small ui-pressable borrower-replace-document"
                                type="button"
                            >
                                Replace
                            </button>

                        </div>

                    </div>

                @endif


                <label
                    class="borrower-upload-field borrower-document-picker"
                    data-picker-state
                    @if($borrowerPtcDocument) hidden @endif
                >

                    <span class="borrower-upload-label">
                        Permission to Conduct Letter (PTC)

                        <span
                            class="borrower-required-mark"
                            aria-hidden="true"
                        >*</span>
                    </span>

                    <input
                        type="file"
                        name="permission_to_conduct_letter"
                        accept=".pdf,.png,.jpg,.jpeg,.webp"
                    >

                    <small class="meta">
                        Required for student activity or organization requests &middot; Maximum 10 MB
                    </small>

                    @error('permission_to_conduct_letter')
                        <small class="field-error">
                            {{ $message }}
                        </small>
                    @enderror

                </label>

            </div>

        @endif

    </div>


    <div
        class="borrower-upload-actions borrower-upload-actions--stateful"
        data-upload-actions
        @if($borrowerRequiredUploadsComplete) hidden @endif
    >

        <button
            class="button primary ui-pressable borrower-upload-documents-button"
            type="submit"
        >
            Upload Documents
        </button>

    </div>

</form>

        </div>


        <div
            class="borrower-completion-divider"
            aria-hidden="true"
        ></div>


        {{-- =====================================================
             STEP 3 — SUBMIT
        ====================================================== --}}

        <div class="borrower-completion-step">

            <div class="borrower-completion-step-heading">
                <span>Step 3</span>
                <h3>Submit to SPMU</h3>
            </div>

            <p class="borrower-completion-copy">
                Send the completed request and signed document(s) to SPMU for
                review.
            </p>

            <small class="borrower-reservation-note">
                Inventory is reserved only after SPMU approval.
            </small>


            <div class="borrower-submit-actions">

                <a
                    class="button secondary ui-pressable"
                    href="{{ route('requests.edit', $borrowingRequest) }}"
                >
                    Edit Draft
                </a>


                <form
                    method="post"
                    action="{{ route('requests.submit', $borrowingRequest) }}"
                >

                    @csrf

                    <button class="button primary ui-pressable borrower-final-submit">
    Submit
</button>

                </form>

            </div>

        </div>

    </article>

</section>

@endif

@if($borrowingRequest->custody)
<section class="content-area">
    <div class="action-panel action-neutral">
        <div>
            <p class="eyebrow">Pickup / custody</p>
            <h2>Operational record available</h2>
            <p>Reservation and pickup/return details are tracked separately from the request.</p>
        </div>

        <a
            class="button primary ui-pressable"
            href="{{ route('custody.show', $borrowingRequest->custody) }}"
        >
            Open Custody Record
        </a>
    </div>
</section>
@endif

@if($pendingCancellation)
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Cancellation requested</p>
                <h2>SPMU confirmation required</h2>
            </div>
            <x-status-badge status="PENDING_SPMU" />
        </div>

        <p><strong>Reason:</strong> {{ $pendingCancellation->reason }}</p>
        <p class="meta">The reservation remains active until SPMU approves this cancellation.</p>

        @if($isSpmu)
            <form
                method="post"
                action="{{ route('requests.cancellation.review', $borrowingRequest) }}"
                class="form-grid"
            >
                @csrf
                <label>
                    Review remarks
                    <textarea name="remarks"></textarea>
                </label>
                <div class="inline-actions">
                    <button
                        class="button primary"
                        name="decision"
                        value="APPROVED"
                    >
                        Confirm Cancellation & Restore Reservation
                    </button>
                    <button
                        class="button danger"
                        name="decision"
                        value="REJECTED"
                    >
                        Reject Cancellation
                    </button>
                </div>
            </form>
        @endif
    </article>
</section>
@endif

@if(
    $isBorrower
    && !in_array(
        $borrowingRequest->status,
        [
            App\Enums\RequestStatus::Cancelled,
            App\Enums\RequestStatus::Rejected,
            App\Enums\RequestStatus::Expired,
        ],
        true
    )
    && !$borrowingRequest->custody?->released_at
    && !$pendingCancellation
)
<section class="content-area">
    <details class="card">
        <summary>Cancel request</summary>
        <form
            method="post"
            action="{{ route('requests.cancel', $borrowingRequest) }}"
            class="form-grid top-gap"
        >
            @csrf
            <label>
                Valid cancellation reason
                <textarea name="reason" required></textarea>
            </label>
            <button class="button danger">
                {{ $borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease
                    ? 'Request Cancellation from SPMU'
                    : 'Cancel Request' }}
            </button>
        </form>
    </details>
</section>
@endif

<section class="content-area">
    <article class="card {{ $isUnderSpmuReview ? 'spmu-history-card' : '' }}">
        <div class="card-header spmu-history-header">
    <div>
        <p class="eyebrow">
            {{ $isUnderSpmuReview ? 'Request history' : 'Audit history' }}
        </p>

        <h2>
            {{ $isUnderSpmuReview ? 'Status changes' : 'Status changes' }}
        </h2>

        @if($isUnderSpmuReview)
            <small class="meta">
                {{ $borrowingRequest->statusHistory->count() }}
                {{ $borrowingRequest->statusHistory->count() === 1 ? 'change' : 'changes' }}
                recorded
            </small>
        @endif
    </div>

    @if($isUnderSpmuReview)
        <button
            type="button"
            class="button secondary small ui-pressable spmu-history-toggle"
            data-spmu-history-toggle
            aria-expanded="false"
        >
            View History
        </button>
    @endif
</div>

        <div class="table-wrap {{ $isUnderSpmuReview ? 'spmu-history-table' : '' }}">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Actor</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($borrowingRequest->statusHistory as $history)
                    <tr>
                        <td>{{ optional($history->changed_at)->format('d M Y, g:i A') }}</td>
                        @php
                            $fromStatus = match($history->from_status) {
                                'UNDER_GSU', 'UNDER_VPAF' => 'LEGACY_REVIEW',
                                default => $history->from_status,
                            };
                            $toStatus = match($history->to_status) {
                                'UNDER_GSU', 'UNDER_VPAF' => 'LEGACY_REVIEW',
                                default => $history->to_status,
                            };
                        @endphp
                        <td>{{ $fromStatus ?: '—' }}</td>
                        <td>{{ $toStatus }}</td>
                        <td>{{ $history->actor?->full_name ?: 'System' }}</td>
                        <td>{{ $history->reason ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">No status history.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>

@if($isUnderSpmuReview)
<style>
.spmu-verification-grid {
    display: grid;
    width: 100%;
    min-width: 0;
    grid-template-columns: minmax(0, 1.25fr) minmax(360px, .75fr);
    gap: 20px;
    align-items: start;
}

.spmu-verification-grid > * {
    min-width: 0;
}

.spmu-document-panel,
.spmu-checklist-panel {
    width: 100%;
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
}

.spmu-checklist-panel {
    align-self: start;
}

.spmu-preview {
    display: grid;
    gap: 12px;
}

.spmu-preview-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
}

.spmu-preview-toolbar__group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.spmu-preview-zoom,
.spmu-preview-page {
    min-width: 58px;
    text-align: center;
    font-size: .875rem;
    font-weight: 700;
    color: var(--text-muted, #64748b);
}

.spmu-preview-stage {
    min-height: 620px;
    height: min(72vh, 820px);
    border: 1px solid var(--border, #d7dee8);
    border-radius: 14px;
    overflow: hidden;
    background: #eef2f7;
}

.spmu-preview-frame {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
    background: #fff;
}

.spmu-preview-image-scroll {
    width: 100%;
    height: 100%;
    overflow: auto;
    display: grid;
    place-items: start center;
    padding: 18px;
}

.spmu-preview-image {
    display: block;
    max-width: 100%;
    height: auto;
    transform-origin: top center;
    transition: transform 120ms ease;
}

.spmu-supporting-document {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    margin: 16px 0;
    border: 1px solid var(--border, #d7dee8);
    border-radius: 12px;
}

.spmu-supporting-document div {
    display: grid;
    gap: 3px;
}

.spmu-supporting-document small,
.spmu-check-row small {
    color: var(--text-muted, #64748b);
}

.spmu-checklist {
    display: grid;
    gap: 10px;
    margin: 18px 0;
}

.spmu-check-row {
    display: grid;
    grid-template-columns: 22px minmax(0, 1fr);
    gap: 12px;
    align-items: start;
    padding: 14px;
    border: 1px solid var(--border, #d7dee8);
    border-radius: 12px;
    cursor: pointer;
}

.spmu-check-row:has(input:checked) {
    border-color: #8ca5c5;
    background: rgba(24, 61, 105, .045);
}

.spmu-check-row input {
    width: 18px;
    height: 18px;
    margin-top: 2px;
}

.spmu-check-row span {
    display: grid;
    gap: 3px;
}

.spmu-check-row strong,
.spmu-check-row small,
.spmu-checklist-panel p,
.spmu-supporting-document strong,
.spmu-supporting-document small {
    overflow-wrap: anywhere;
    word-break: normal;
}

.spmu-preview-help {
    margin: 0;
    color: var(--text-muted, #64748b);
    font-size: .8rem;
    line-height: 1.45;
}

.spmu-document-status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 14px 0;
    border-top: 1px solid var(--border, #d7dee8);
    border-bottom: 1px solid var(--border, #d7dee8);
}

.spmu-remarks {
    display: grid;
    gap: 8px;
    margin-top: 16px;
}

.spmu-remarks label {
    display: grid;
    gap: 3px;
}

.spmu-decision-actions {
    display: grid;
    gap: 10px;
    margin-top: 18px;
}

.spmu-decision-actions .button {
    width: 100%;
}

.field-error {
    margin: 0;
    color: #b42318;
    font-size: .875rem;
}

.spmu-confirm-dialog {
    width: min(520px, calc(100vw - 32px));
    padding: 0;
    border: 0;
    border-radius: 18px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .25);
}

.spmu-confirm-dialog::backdrop {
    background: rgba(15, 23, 42, .52);
}

.spmu-confirm-dialog__surface {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr);
    gap: 16px;
    padding: 24px;
    background: var(--surface, #fff);
}

.spmu-confirm-dialog__icon {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    font-size: 1.2rem;
    font-weight: 800;
    background: #fff4db;
    color: #8a5a00;
}

.spmu-confirm-dialog__surface h2 {
    margin-top: 2px;
}

.spmu-confirm-dialog__actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 4px;
}

@media (max-width: 1120px) {
    .spmu-verification-grid {
        grid-template-columns: 1fr;
    }

    .spmu-preview-stage {
        min-height: 480px;
        height: 62vh;
    }
}

@media (max-width: 620px) {
    .spmu-preview-toolbar,
    .spmu-supporting-document,
    .spmu-confirm-dialog__actions {
        align-items: stretch;
        flex-direction: column;
    }

    .spmu-preview-toolbar__group {
        justify-content: center;
    }

    .spmu-preview-stage {
        min-height: 400px;
        height: 56vh;
    }

    .spmu-confirm-dialog__actions .button {
        width: 100%;
    }
}
</style>

<script>
(() => {
    const workspace = document.querySelector('[data-spmu-verification-workspace]');

    if (!workspace) {
        return;
    }

    const form = workspace.querySelector('[data-verification-form]');
    const dialog = document.querySelector('[data-verification-confirm-dialog]');

    if (!form || !dialog) {
        return;
    }

    const checks = [...form.querySelectorAll('[data-verification-check]')];
    const approveButton = form.querySelector('[data-approve-button]');
    const documentStatus = form.querySelector('[data-document-status]');
    const decisionInput = form.querySelector('[data-verification-decision]');
    const remarksWrap = form.querySelector('[data-verification-remarks-wrap]');
    const remarks = form.querySelector('[data-verification-remarks]');
    const inlineError = form.querySelector('[data-verification-inline-error]');
    const triggers = [...form.querySelectorAll('[data-decision-trigger]')];
    const confirmTitle = dialog.querySelector('[data-confirm-title]');
    const confirmMessage = dialog.querySelector('[data-confirm-message]');
    const confirmSubmit = dialog.querySelector('[data-confirm-submit]');
    const confirmCancel = dialog.querySelector('[data-confirm-cancel]');
    const requiredSupportingPresent = form.dataset.requiredSupportingPresent === '1';

    const decisionCopy = {
        APPROVED: {
            title: 'Verify and approve this request?',
            message: 'This will mark the current scanned documents as verified and reserve the exact approved quantities. Continue?',
            confirm: 'Yes, verify & approve',
            tone: 'primary',
        },
        RETURNED_FOR_REVISION: {
            title: 'Return this request for revision?',
            message: 'The borrower will receive your remarks and must correct the request/documents before resubmitting. No reservation will be created.',
            confirm: 'Yes, return for revision',
            tone: 'secondary',
        },
        REJECTED: {
            title: 'Reject this request?',
            message: 'This will reject the request and no reservation will be created. This decision should only be used when rejection is appropriate.',
            confirm: 'Yes, reject request',
            tone: 'danger',
        },
    };

    const checklistComplete = () =>
        requiredSupportingPresent
        && checks.length === 3
        && checks.every((checkbox) => checkbox.checked);

    const updateStatus = () => {
        const complete = checklistComplete();

        if (documentStatus) {
            documentStatus.textContent = complete ? 'Complete' : 'Incomplete';
            documentStatus.classList.toggle('status-success', complete);
            documentStatus.classList.toggle('status-warning', !complete);
        }

        if (approveButton) {
            approveButton.disabled = !complete;
        }
    };

    const showInlineError = (message = '') => {
        if (!inlineError) {
            return;
        }

        inlineError.textContent = message;
        inlineError.hidden = message === '';
    };

    const showRemarks = (helpText) => {
        remarksWrap.hidden = false;
        const help = remarksWrap.querySelector('[data-verification-remarks-help]');
        if (help && helpText) {
            help.textContent = helpText;
        }
    };

    const openConfirmation = (decision) => {
        const copy = decisionCopy[decision];

        decisionInput.value = decision;
        confirmTitle.textContent = copy.title;
        confirmMessage.textContent = copy.message;
        confirmSubmit.textContent = copy.confirm;
        confirmSubmit.className = `button ${copy.tone} ui-pressable`;

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else if (window.confirm(`${copy.title}\n\n${copy.message}`)) {
            form.submit();
        }
    };

    checks.forEach((checkbox) => {
        checkbox.addEventListener('change', updateStatus);
    });

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const decision = trigger.dataset.decisionTrigger;

            showInlineError('');

            if (decision === 'APPROVED') {
                if (!checklistComplete()) {
                    showInlineError(
                        'Complete all three checklist confirmations and verify every required supporting document before approving.'
                    );
                    return;
                }

                openConfirmation(decision);
                return;
            }

            const label = decision === 'REJECTED'
                ? 'Required: state the reason for rejecting this request.'
                : 'Required: state exactly what the borrower needs to revise.';

            showRemarks(label);

            if (!remarks.value.trim()) {
                showInlineError(
                    decision === 'REJECTED'
                        ? 'Enter rejection remarks before continuing.'
                        : 'Enter revision remarks before continuing.'
                );
                remarks.focus();
                return;
            }

            openConfirmation(decision);
        });
    });

    confirmCancel?.addEventListener('click', () => {
        dialog.close();
    });

    confirmSubmit?.addEventListener('click', () => {
        confirmSubmit.disabled = true;
        form.submit();
    });

    dialog.addEventListener('cancel', () => {
        decisionInput.value = '';
    });

    updateStatus();
})();
</script>
@endif


<style>
/* REQUEST SHOW UI POLISH */

.request-scan-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 0;
    border-top: 1px solid #e2e8f0;
}

.request-scan-main {
    min-width: 0;
    flex: 1 1 auto;
}

.request-scan-title {
    display: block;
    margin: 0 0 6px;
    color: #10233d;
    font-size: 16px;
    line-height: 1.4;
}

.request-scan-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    color: #6b7b91;
    font-size: 13px;
}

.request-scan-status {
    color: #1769e0;
    font-weight: 700;
}

.request-scan-button {
    flex: 0 0 auto;
    min-width: 110px;
    text-align: center;
}

.request-scan-note {
    margin-top: 14px;
}

/* Upload area */
.borrower-completion-upload-form {
    display: block;
    margin-top: 14px;
}

.borrower-upload-actions {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    margin-top: 14px;
}

.borrower-upload-actions .button {
    min-width: 120px;
}

/* Submit area */
.borrower-submit-actions {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 18px;
}

.borrower-submit-actions form {
    margin: 0;
}

.borrower-submit-actions .button,
.borrower-submit-actions form .button {
    min-width: 120px;
}

/* Force the submit button to be blue */
.borrower-submit-actions form .button {
    background: #1769e0;
    border-color: #1769e0;
    color: #fff;
}

.borrower-submit-actions form .button:hover,
.borrower-submit-actions form .button:focus {
    background: #0f5ac7;
    border-color: #0f5ac7;
    color: #fff;
}

/* Step tracker colors */
.borrower-completion-step-number {
    border: 1px solid #cad5e2;
    box-sizing: border-box;
}

.borrower-completion-steps li.is-pending {
    background: #f8fafc;
}

.borrower-completion-steps li.is-current,
.borrower-completion-steps li.is-ready {
    background: #eef5ff;
}

.borrower-completion-steps li.is-complete {
    background: #edf9f1;
}

.borrower-completion-steps li.is-pending .borrower-completion-step-number {
    background: #eef2f7;
    border-color: #cad5e2;
    color: #52647a;
}

.borrower-completion-steps li.is-current .borrower-completion-step-number,
.borrower-completion-steps li.is-ready .borrower-completion-step-number {
    background: #1769e0;
    border-color: #1769e0;
    color: #fff;
}

.borrower-completion-steps li.is-complete .borrower-completion-step-number {
    background: #23855b;
    border-color: #23855b;
    color: #fff;
}

.borrower-completion-steps li.is-complete strong {
    color: #176a49;
}

.borrower-completion-steps li.is-complete small {
    color: #176a49;
}

/* Mobile */
@media (max-width: 760px) {
    .request-scan-row {
        align-items: stretch;
        flex-direction: column;
    }

    .request-scan-button {
        width: 100%;
    }

    .borrower-upload-actions,
    .borrower-upload-actions .button,
    .borrower-submit-actions,
    .borrower-submit-actions > *,
    .borrower-submit-actions form,
    .borrower-submit-actions .button {
        width: 100%;
    }
}

/* Dark theme */
html[data-theme="dark"] .request-scan-row {
    border-top-color: rgba(255,255,255,.10);
}

html[data-theme="dark"] .request-scan-title {
    color: #edf4ff;
}

html[data-theme="dark"] .request-scan-meta {
    color: #b8c7da;
}

html[data-theme="dark"] .request-scan-status {
    color: #7fb0ff;
}

html[data-theme="dark"] .borrower-completion-steps li.is-pending {
    background: rgba(255,255,255,.03);
}

html[data-theme="dark"] .borrower-completion-steps li.is-current,
html[data-theme="dark"] .borrower-completion-steps li.is-ready {
    background: rgba(23,105,224,.14);
}

html[data-theme="dark"] .borrower-completion-steps li.is-complete {
    background: rgba(35,133,91,.16);
}

/* STEP 3 BLUE SUBMIT BUTTON */

.borrower-submit-actions .borrower-final-submit {
    background: #1769e0 !important;
    border-color: #1769e0 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 7px rgba(23, 105, 224, .18) !important;
}

.borrower-submit-actions .borrower-final-submit:hover,
.borrower-submit-actions .borrower-final-submit:focus-visible {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(23, 105, 224, .22) !important;
}

.borrower-submit-actions .borrower-final-submit:active {
    background: #0b4fae !important;
    border-color: #0b4fae !important;
    color: #ffffff !important;
    transform: scale(.97);
}
</style>


<style>
/* STATEFUL STEP 2 DOCUMENT UPLOAD */

.borrower-upload-fields--stateful {
    display: grid;
    grid-template-columns: repeat(
        auto-fit,
        minmax(320px, 1fr)
    );
    gap: 16px;
    align-items: stretch;
}

.borrower-document-slot {
    min-width: 0;
}

.borrower-document-picker {
    height: 100%;
}

.borrower-document-picker[hidden],
.borrower-document-uploaded[hidden],
.borrower-upload-actions--stateful[hidden] {
    display: none !important;
}


/* Uploaded document state */

.borrower-document-uploaded {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 16px;

    min-height: 134px;
    height: 100%;

    padding: 16px;

    border: 1px solid #cce7d7;
    border-radius: 10px;

    background: #f4fbf7;
}

.borrower-document-uploaded-main {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    min-width: 0;
}

.borrower-document-check {
    flex: 0 0 30px;

    width: 30px;
    height: 30px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 999px;

    background: #23855b;
    color: #ffffff;

    font-size: 14px;
    font-weight: 800;
}

.borrower-document-uploaded-info {
    min-width: 0;
}

.borrower-document-uploaded-info strong {
    display: block;

    color: #183429;

    font-size: 14px;
    line-height: 1.4;
}

.borrower-document-file-name {
    display: block;

    margin-top: 4px;

    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    color: #50675d;

    font-size: 12px;
}

.borrower-document-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 5px;

    margin-top: 6px;

    color: #657b71;

    font-size: 12px;
}

.borrower-document-status {
    color: #23855b;
    font-weight: 700;
}


/* View / Replace actions */

.borrower-document-card-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.borrower-document-card-actions .button {
    min-width: 92px;
    justify-content: center;
    white-space: nowrap;
}


/* Single upload action */

.borrower-upload-actions--stateful {
    display: flex;
    align-items: center;
    justify-content: flex-start;

    padding-top: 0;
    margin-top: 16px;
}

.borrower-upload-documents-button {
    min-width: 154px;

    background: #1769e0 !important;
    border-color: #1769e0 !important;
    color: #ffffff !important;
}

.borrower-upload-documents-button:hover,
.borrower-upload-documents-button:focus-visible {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
    color: #ffffff !important;
}


/* Dark theme */

html[data-theme="dark"] .borrower-document-uploaded {
    border-color: rgba(78, 190, 132, .30);
    background: rgba(35, 133, 91, .12);
}

html[data-theme="dark"]
.borrower-document-uploaded-info strong {
    color: #e8f8ef;
}

html[data-theme="dark"]
.borrower-document-file-name,
html[data-theme="dark"]
.borrower-document-meta {
    color: #b8d3c3;
}

html[data-theme="dark"]
.borrower-document-status {
    color: #79d6a5;
}


/* Mobile */

@media (max-width: 760px) {

    .borrower-upload-fields--stateful {
        grid-template-columns: 1fr;
    }

    .borrower-document-uploaded {
        min-height: 0;
    }

    .borrower-document-card-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .borrower-document-card-actions .button {
        width: 100%;
    }

    .borrower-upload-actions--stateful,
    .borrower-upload-actions--stateful .button {
        width: 100%;
    }
}
</style>


<script>
document.addEventListener('click', function (event) {
    const replaceButton =
        event.target.closest('.borrower-replace-document');

    if (! replaceButton) {
        return;
    }

    const slot =
        replaceButton.closest('.borrower-document-slot');

    if (! slot) {
        return;
    }

    const uploadedState =
        slot.querySelector('[data-uploaded-state]');

    const pickerState =
        slot.querySelector('[data-picker-state]');

    if (uploadedState) {
        uploadedState.hidden = true;
    }

    if (pickerState) {
        pickerState.hidden = false;

        const input =
            pickerState.querySelector('input[type="file"]');

        if (input) {
            input.focus();
        }
    }

    const form =
        replaceButton.closest(
            '.borrower-stateful-upload-form'
        );

    const uploadActions =
        form
            ? form.querySelector('[data-upload-actions]')
            : null;

    if (uploadActions) {
        uploadActions.hidden = false;
    }
});
</script>

<style>
/* SPMU REVIEW WORKSPACE REDESIGN */


/* ============================================================
   REVIEW CONTEXT
   ============================================================ */

.spmu-review-context {
    display: grid;
    grid-template-columns:
        minmax(150px, .85fr)
        minmax(200px, 1.15fr)
        minmax(190px, 1fr)
        minmax(150px, .8fr);

    gap: 0;

    margin-bottom: 16px;

    overflow: hidden;

    border: 1px solid #d8e2ef;
    border-radius: 12px;

    background: #ffffff;
}

.spmu-review-context-item {
    min-width: 0;

    padding: 12px 16px;

    border-right: 1px solid #e1e8f0;
}

.spmu-review-context-item:last-child {
    border-right: 0;
}

.spmu-review-context-item > span {
    display: block;

    margin-bottom: 3px;

    color: #728197;

    font-size: 10px;
    font-weight: 800;
    letter-spacing: .07em;
    text-transform: uppercase;
}

.spmu-review-context-item > strong {
    display: block;

    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    color: #132c4c;

    font-size: 13px;
}


/* ============================================================
   MAIN REVIEW GRID
   ============================================================ */

.spmu-verification-grid {
    display: grid !important;

    grid-template-columns:
        minmax(0, 1.7fr)
        minmax(330px, .9fr) !important;

    align-items: start !important;

    gap: 20px !important;
}


/* Keep the PDF useful without making the page excessively tall */

.spmu-verification-grid iframe,
.spmu-verification-grid embed,
.spmu-verification-grid object {
    height: 70vh !important;
    min-height: 560px;
    max-height: 760px;
}


/* ============================================================
   STICKY SPMU REVIEW PANEL
   ============================================================ */

.spmu-checklist-panel {
    position: sticky;
    top: 82px;

    align-self: start;

    padding-bottom: 16px;
}

.spmu-checklist-panel .card-header {
    margin-bottom: 10px;
}

.spmu-checklist-panel > .meta {
    margin-bottom: 12px;

    line-height: 1.45;
}


/* ============================================================
   SUPPORTING DOCUMENT
   ============================================================ */

.spmu-supporting-document {
    display: flex !important;

    align-items: center !important;
    justify-content: space-between !important;

    gap: 12px !important;

    margin: 12px 0 !important;
    padding: 11px 12px !important;

    border: 1px solid #d8e3ef !important;
    border-radius: 10px !important;

    background: #f8fbff !important;
}

.spmu-supporting-document strong {
    display: block;

    margin-bottom: 2px;

    color: #18304f;

    font-size: 13px;
}

.spmu-supporting-document small {
    display: block;

    color: #6c7e94;

    font-size: 11px;
    line-height: 1.4;
}

.spmu-supporting-document .button {
    flex: 0 0 auto;

    min-width: 96px;

    white-space: nowrap;
}


/* ============================================================
   COMPACT CHECKLIST
   ============================================================ */

.spmu-verification-form {
    display: grid;

    gap: 8px;
}

/*
 * Modern Chromium supports :has().
 * This tightens checkbox labels without changing any logic.
 */

.spmu-verification-form label:has(input[type="checkbox"]) {
    margin: 0 !important;
    padding: 10px 12px !important;

    border-radius: 9px !important;
}

.spmu-verification-form input[type="checkbox"] {
    width: 18px;
    height: 18px;
}


/* ============================================================
   DECISION STATUS + ACTIONS
   ============================================================ */

.spmu-decision-actions {
    display: grid !important;

    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr) !important;

    gap: 9px !important;

    margin-top: 12px !important;
}

.spmu-decision-actions .button {
    min-height: 40px;

    justify-content: center;
}


/* Primary approval action */

.spmu-decision-actions
[data-decision-trigger="APPROVED"] {
    grid-column: 1 / -1;

    background: #1769e0 !important;
    border-color: #1769e0 !important;
    color: #ffffff !important;

    box-shadow:
        0 2px 7px rgba(23, 105, 224, .16);
}

.spmu-decision-actions
[data-decision-trigger="APPROVED"]:hover:not(:disabled) {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
}


/* Disabled until checklist complete */

.spmu-decision-actions
[data-decision-trigger="APPROVED"]:disabled {
    background: #e8edf4 !important;
    border-color: #d5dde8 !important;
    color: #8a98aa !important;

    box-shadow: none;

    cursor: not-allowed;
}


/* Return = warning / corrective action */

.spmu-decision-actions
[data-decision-trigger="RETURNED_FOR_REVISION"] {
    background: #ffffff !important;
    border-color: #d69b2d !important;
    color: #966514 !important;
}

.spmu-decision-actions
[data-decision-trigger="RETURNED_FOR_REVISION"]:hover {
    background: #fff8e9 !important;
}


/* Reject = destructive action */

.spmu-decision-actions
[data-decision-trigger="REJECTED"] {
    background: #ffffff !important;
    border-color: #d65454 !important;
    color: #b73232 !important;
}

.spmu-decision-actions
[data-decision-trigger="REJECTED"]:hover {
    background: #fff2f2 !important;
}


/* Reservation helper */

.spmu-decision-actions + .meta {
    margin-top: 10px;

    padding: 9px 10px;

    border-radius: 8px;

    background: #f5f8fc;

    color: #66788e;

    font-size: 11px;
    line-height: 1.45;
}


/* ============================================================
   REQUEST SUMMARY
   ============================================================ */

.spmu-request-summary-grid {
    grid-template-columns: 1fr !important;
}

.spmu-request-summary-card {
    width: 100%;
}

.spmu-request-summary-card .card-header {
    margin-bottom: 4px;
}

.spmu-request-summary-card .detail-list {
    display: grid;

    grid-template-columns:
        140px minmax(160px, 1fr)
        150px minmax(160px, 1fr);

    align-items: center;
}

.spmu-request-summary-card .detail-list dt,
.spmu-request-summary-card .detail-list dd {
    min-height: 42px;

    display: flex;
    align-items: center;

    margin: 0;

    border-bottom: 1px solid #e1e7ef;
}

.spmu-request-summary-card .detail-list dt {
    padding-right: 12px;

    color: #64778e;

    font-size: 11px;
    font-weight: 700;
}

.spmu-request-summary-card .detail-list dd {
    padding-right: 20px;

    color: #162b47;

    font-size: 13px;
}


/* ============================================================
   REQUESTED ITEMS
   ============================================================ */

.spmu-request-summary-grid + .content-area {
    margin-top: 16px;
}

.spmu-verification-workspace + dialog
    + .content-grid {
    margin-top: 18px;
}


/* ============================================================
   COLLAPSED REQUEST HISTORY
   ============================================================ */

.spmu-history-card .spmu-history-header {
    align-items: center;

    margin-bottom: 0;
}

.spmu-history-card .spmu-history-header h2 {
    margin-bottom: 2px;
}

.spmu-history-card:not(.is-open)
.spmu-history-table {
    display: none;
}

.spmu-history-card.is-open
.spmu-history-table {
    display: block;

    margin-top: 14px;
}

.spmu-history-toggle {
    min-width: 104px;
}


/* ============================================================
   DARK THEME
   ============================================================ */

html[data-theme="dark"]
.spmu-review-context {
    border-color: rgba(255,255,255,.10);
    background: rgba(255,255,255,.035);
}

html[data-theme="dark"]
.spmu-review-context-item {
    border-color: rgba(255,255,255,.08);
}

html[data-theme="dark"]
.spmu-review-context-item > strong {
    color: #e8f0fb;
}

html[data-theme="dark"]
.spmu-supporting-document {
    border-color: rgba(255,255,255,.10) !important;
    background: rgba(255,255,255,.035) !important;
}

html[data-theme="dark"]
.spmu-supporting-document strong {
    color: #edf4ff;
}

html[data-theme="dark"]
.spmu-decision-actions
[data-decision-trigger="RETURNED_FOR_REVISION"],
html[data-theme="dark"]
.spmu-decision-actions
[data-decision-trigger="REJECTED"] {
    background: rgba(255,255,255,.025) !important;
}


/* ============================================================
   RESPONSIVE
   ============================================================ */

@media (max-width: 1100px) {

    .spmu-review-context {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .spmu-review-context-item:nth-child(2) {
        border-right: 0;
    }

    .spmu-review-context-item:nth-child(-n+2) {
        border-bottom: 1px solid #e1e8f0;
    }

    .spmu-verification-grid {
        grid-template-columns: 1fr !important;
    }

    .spmu-checklist-panel {
        position: static;
    }

    .spmu-request-summary-card .detail-list {
        grid-template-columns:
            135px minmax(0, 1fr);
    }
}


@media (max-width: 700px) {

    .spmu-review-context {
        grid-template-columns: 1fr;
    }

    .spmu-review-context-item {
        border-right: 0;
        border-bottom: 1px solid #e1e8f0;
    }

    .spmu-review-context-item:last-child {
        border-bottom: 0;
    }

    .spmu-decision-actions {
        grid-template-columns: 1fr !important;
    }

    .spmu-decision-actions
    [data-decision-trigger="APPROVED"] {
        grid-column: auto;
    }

    .spmu-request-summary-card .detail-list {
        display: block;
    }

    .spmu-request-summary-card .detail-list dt {
        min-height: 0;

        padding-top: 10px;

        border-bottom: 0;
    }

    .spmu-request-summary-card .detail-list dd {
        min-height: 0;

        padding: 4px 0 10px;
    }
}
</style>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const historyToggle =
        document.querySelector(
            '[data-spmu-history-toggle]'
        );

    if (! historyToggle) {
        return;
    }

    const historyCard =
        historyToggle.closest(
            '.spmu-history-card'
        );

    if (! historyCard) {
        return;
    }

    historyToggle.addEventListener(
        'click',
        function () {

            const open =
                historyCard.classList.toggle(
                    'is-open'
                );

            historyToggle.setAttribute(
                'aria-expanded',
                open ? 'true' : 'false'
            );

            historyToggle.textContent =
                open
                    ? 'Hide History'
                    : 'View History';
        }
    );
});
</script>


<style>
/* SPMU SOLID DECISION BUTTON OVERRIDES */

.spmu-decision-actions [data-decision-trigger="RETURNED_FOR_REVISION"] {
    background: #d69b2d !important;
    border-color: #d69b2d !important;
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(214, 155, 45, .18);
}

.spmu-decision-actions [data-decision-trigger="RETURNED_FOR_REVISION"]:hover:not(:disabled),
.spmu-decision-actions [data-decision-trigger="RETURNED_FOR_REVISION"]:focus:not(:disabled) {
    background: #be861c !important;
    border-color: #be861c !important;
    color: #ffffff !important;
}

.spmu-decision-actions [data-decision-trigger="REJECTED"] {
    background: #d65454 !important;
    border-color: #d65454 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(214, 84, 84, .18);
}

.spmu-decision-actions [data-decision-trigger="REJECTED"]:hover:not(:disabled),
.spmu-decision-actions [data-decision-trigger="REJECTED"]:focus:not(:disabled) {
    background: #bf3f3f !important;
    border-color: #bf3f3f !important;
    color: #ffffff !important;
}
</style>


<style>
/* SPMU CONFIRMATION MODAL REDESIGN */


/* ============================================================
   DIALOG + BACKDROP
   ============================================================ */

.spmu-confirm-dialog {
    width: min(540px, calc(100vw - 32px));
    max-width: 540px;

    padding: 0;

    border: 0;
    border-radius: 18px;

    background: transparent;

    overflow: visible;
}

.spmu-confirm-dialog::backdrop {
    background: rgba(16, 32, 54, .58);
    backdrop-filter: blur(2px);
}


/* ============================================================
   MODAL SURFACE
   ============================================================ */

.spmu-confirm-dialog__surface {
    display: grid;

    grid-template-columns: 46px minmax(0, 1fr);
    gap: 16px;

    width: 100%;

    padding: 24px;

    border: 1px solid rgba(15, 52, 92, .08);
    border-radius: 18px;

    background: #ffffff;

    box-shadow:
        0 24px 70px rgba(11, 40, 84, .24),
        0 8px 22px rgba(11, 40, 84, .10);
}


/* ============================================================
   ICON
   ============================================================ */

.spmu-confirm-dialog__icon {
    width: 46px;
    height: 46px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    flex: 0 0 46px;

    border-radius: 999px;

    background: #fff3d6;
    color: #a66700;

    font-size: 21px;
    font-weight: 900;
    line-height: 1;

    box-shadow:
        inset 0 0 0 1px rgba(214, 155, 45, .16);
}


/* ============================================================
   COPY
   ============================================================ */

.spmu-confirm-dialog__copy {
    min-width: 0;
}

.spmu-confirm-dialog__eyebrow {
    margin: 1px 0 6px;

    color: #667a93;

    font-size: 10px;
    font-weight: 800;
    letter-spacing: .10em;
    text-transform: uppercase;
}

.spmu-confirm-dialog__surface h2 {
    margin: 0;

    color: #102c50;

    font-size: 20px;
    line-height: 1.3;
    font-weight: 750;
}

.spmu-confirm-dialog__message {
    margin: 10px 0 0;

    max-width: 430px;

    color: #52657b;

    font-size: 14px;
    line-height: 1.6;
}


/* ============================================================
   ACTIONS
   ============================================================ */

.spmu-confirm-dialog__actions {
    grid-column: 1 / -1;

    display: flex;
    align-items: center;
    justify-content: flex-end;

    gap: 10px;

    margin-top: 6px;
    padding-top: 18px;

    border-top: 1px solid #e3e9f0;
}

.spmu-confirm-dialog__actions .button {
    min-height: 42px;

    padding: 0 17px;

    border-radius: 9px;

    font-size: 13px;
    font-weight: 700;

    justify-content: center;
}


/* Cancel */

.spmu-confirm-dialog__actions
[data-confirm-cancel] {
    background: #ffffff !important;
    border-color: #c9d4e1 !important;
    color: #43566f !important;
}

.spmu-confirm-dialog__actions
[data-confirm-cancel]:hover {
    background: #f4f7fa !important;
    border-color: #aebdce !important;
    color: #263d5b !important;
}


/* APPROVE = BLUE */

.spmu-confirm-dialog__actions
[data-confirm-submit].primary {
    background: #1769e0 !important;
    border-color: #1769e0 !important;
    color: #ffffff !important;

    box-shadow:
        0 3px 9px rgba(23, 105, 224, .18);
}

.spmu-confirm-dialog__actions
[data-confirm-submit].primary:hover {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
}


/* RETURN FOR REVISION = AMBER */

.spmu-confirm-dialog__actions
[data-confirm-submit].secondary {
    background: #d69b2d !important;
    border-color: #d69b2d !important;
    color: #ffffff !important;

    box-shadow:
        0 3px 9px rgba(214, 155, 45, .18);
}

.spmu-confirm-dialog__actions
[data-confirm-submit].secondary:hover {
    background: #bd841b !important;
    border-color: #bd841b !important;
}


/* REJECT = RED */

.spmu-confirm-dialog__actions
[data-confirm-submit].danger {
    background: #d65454 !important;
    border-color: #d65454 !important;
    color: #ffffff !important;

    box-shadow:
        0 3px 9px rgba(214, 84, 84, .18);
}

.spmu-confirm-dialog__actions
[data-confirm-submit].danger:hover {
    background: #bd4141 !important;
    border-color: #bd4141 !important;
}


/* Press feedback */

.spmu-confirm-dialog__actions .button:active {
    transform: scale(.97);
}


/* ============================================================
   OPEN ANIMATION
   ============================================================ */

.spmu-confirm-dialog[open]
.spmu-confirm-dialog__surface {
    animation:
        spmu-confirm-enter 170ms ease-out;
}

@keyframes spmu-confirm-enter {

    from {
        opacity: 0;
        transform:
            translateY(8px)
            scale(.985);
    }

    to {
        opacity: 1;
        transform:
            translateY(0)
            scale(1);
    }
}


/* ============================================================
   DARK THEME
   ============================================================ */

html[data-theme="dark"]
.spmu-confirm-dialog__surface {
    border-color: rgba(255,255,255,.08);

    background: #172235;

    box-shadow:
        0 24px 70px rgba(0,0,0,.42);
}

html[data-theme="dark"]
.spmu-confirm-dialog__surface h2 {
    color: #eef5ff;
}

html[data-theme="dark"]
.spmu-confirm-dialog__message {
    color: #b8c7da;
}

html[data-theme="dark"]
.spmu-confirm-dialog__actions {
    border-color: rgba(255,255,255,.10);
}

html[data-theme="dark"]
.spmu-confirm-dialog__actions
[data-confirm-cancel] {
    background: rgba(255,255,255,.04) !important;
    border-color: rgba(255,255,255,.16) !important;
    color: #d6e2f1 !important;
}


/* ============================================================
   MOBILE
   ============================================================ */

@media (max-width: 600px) {

    .spmu-confirm-dialog {
        width: calc(100vw - 24px);
    }

    .spmu-confirm-dialog__surface {
        grid-template-columns: 40px minmax(0, 1fr);

        gap: 12px;

        padding: 20px;
    }

    .spmu-confirm-dialog__icon {
        width: 40px;
        height: 40px;

        flex-basis: 40px;

        font-size: 18px;
    }

    .spmu-confirm-dialog__surface h2 {
        font-size: 18px;
    }

    .spmu-confirm-dialog__actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .spmu-confirm-dialog__actions .button {
        width: 100%;
    }
}
</style>
@endsection
