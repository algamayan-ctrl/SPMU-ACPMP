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

@if(session('status'))
<section class="content-area">
    <div class="callout success">{{ session('status') }}</div>
</section>
@endif

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

                    <p class="meta">
                        Approval creates the inventory reservation. Returning or rejecting does not create a reservation.
                    </p>
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
        <div>
            <p class="eyebrow">Confirm decision</p>
            <h2 id="spmu-confirm-title" data-confirm-title>Are you sure?</h2>
            <p data-confirm-message>
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

<section class="content-grid two">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Request details</p>
                <h2>Borrowing information</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Borrower</dt>
            <dd>{{ $borrowingRequest->borrower->full_name }}</dd>

            <dt>Office / Department</dt>
            <dd>{{ $borrowingRequest->borrower->organizationalUnit?->unit_name ?: '—' }}</dd>

            <dt>Purpose / Event</dt>
            <dd>{{ $v->purpose_event }}</dd>

            <dt>Event Details</dt>
            <dd>{{ $v->event_details }}</dd>

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
            <div class="evidence-row">
                <div>
                    <strong>
                        {{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                            ? 'Approved Borrowing Request Letter'
                            : 'Permission to Conduct Letter' }}
                    </strong>
                    <small>
                        Version {{ $doc->version_no }}
                        ·
                        {{ str($doc->verification_status)->replace('_',' ')->title() }}
                    </small>
                </div>

                <a
                    class="button secondary small ui-pressable"
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

        <p class="meta">
            The uploaded letter is evidence/notice of the institutionally approved request. It does not reserve inventory until SPMU verifies and approves it in the system.
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
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Physical signature step</p>
                <h2>Print, sign, scan, then upload</h2>
            </div>
        </div>

        <p>
            Use the current system-generated Borrowing Request Letter. Print it,
            obtain the required handwritten/wet signatures from GSU and VPAF,
            then scan the fully accomplished letter and upload it through
            <strong>Edit Draft</strong>. SPMU verifies the uploaded scan in the system.
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
                <form method="post" action="{{ route('requests.recover-draft-document', $borrowingRequest) }}">
                    @csrf
                    <button class="button primary ui-pressable">
                        Generate Request Letter
                    </button>
                </form>
            @endif

            <a
                class="button secondary ui-pressable"
                href="{{ route('requests.edit', $borrowingRequest) }}"
            >
                Upload Signed Scan
            </a>
        </div>

        <p class="meta">
            GSU and VPAF are physical signatories only. They do not have system
            approval queues, portals, or electronic-signature actions.
        </p>
    </article>
</section>
@endif

@if($isBorrower && in_array($borrowingRequest->status, [App\Enums\RequestStatus::Draft, App\Enums\RequestStatus::ReturnedForRevision], true))
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Borrower action</p>
                <h2>Review before submission</h2>
            </div>
        </div>

        <p>
            Submission sends the request and scanned approved document(s) to SPMU. It does
            <strong>not</strong>
            reserve inventory.
        </p>

        <div class="inline-actions">
            <a
                class="button secondary ui-pressable"
                href="{{ route('requests.edit', $borrowingRequest) }}"
            >
                Edit Draft
            </a>

            <form method="post" action="{{ route('requests.submit', $borrowingRequest) }}">
                @csrf
                <button class="button primary ui-pressable">Submit to SPMU</button>
            </form>
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
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Audit history</p>
                <h2>Status changes</h2>
            </div>
        </div>

        <div class="table-wrap">
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
@endsection
