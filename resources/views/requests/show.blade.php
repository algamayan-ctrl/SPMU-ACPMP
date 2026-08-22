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

    // A request remains APPROVED_READY_FOR_RELEASE at the request-record level
    // after approval, while the custody transaction continues through release,
    // return, and completion. Use custody state for the visible operational
    // badge so the request detail never shows a stale "Ready for Release".
    $custody = $borrowingRequest->custody;
    $requestIsCompleted = $custody?->status === 'CLOSED';
    $detailStatus = $borrowingRequest->status->value;
    $detailStatusLabel = null;

    $isBorrowerDraftWorkflow = $isBorrower
        && in_array(
            $borrowingRequest->status,
            [
                App\Enums\RequestStatus::Draft,
                App\Enums\RequestStatus::ReturnedForRevision,
            ],
            true
        );

    $signedRequestLetterReady = (bool) $requestLetterDoc;
    $ptcRequired = (bool) $v->represents_student_activity;
    $ptcReady = ! $ptcRequired || (bool) $permissionToConductDoc;
    $submissionReady = $signedRequestLetterReady && $ptcReady;

    if ($custody) {
        $preparationComplete = (bool) $custody->prepared_at;
        $hasPickupSchedule = (bool) $custody->scheduled_release_at
            && (bool) $custody->pickup_expires_at
            && ! $custody->pickup_expired_at;

        [$detailStatus, $detailStatusLabel] = match (true) {
            $custody->status === 'CLOSED' => ['CLOSED', 'Completed'],
            $custody->status === 'PREPARING_RELEASE' && $custody->pickup_expired_at => ['PREPARING_RELEASE', 'Pickup Window Expired'],
            $custody->status === 'PREPARING_RELEASE' && $preparationComplete && $hasPickupSchedule => ['READY_FOR_RELEASE', 'Ready for Release'],
            $custody->status === 'PREPARING_RELEASE' && ! $hasPickupSchedule => ['PREPARING_RELEASE', 'For Pickup Scheduling'],
            $custody->status === 'PREPARING_RELEASE' && $hasPickupSchedule && ! $preparationComplete => ['PREPARING_RELEASE', 'For Item Preparation'],
            $custody->status === 'ACTIVE' && (bool) $custody->released_at => ['BORROWED', 'Items Released / On Custody'],
            default => [$custody->status, null],
        };
    }
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">{{ $isBorrower ? 'My request' : 'Borrowing request' }}</p>
        <h1>{{ $borrowingRequest->request_no }}</h1>
        <p>{{ $v->purpose_event }}</p>
        @if($isBorrower)
            <p class="meta">Request status, approval history, signed documents, and requested/approved items.</p>
        @endif
    </div>
    <x-status-badge :status="$detailStatus" :label="$detailStatusLabel" />
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

@if($isBorrowerDraftWorkflow)
<section class="content-area" id="borrower-next-action">
    <div class="action-panel action-neutral">
        <div>
            <p class="eyebrow">Current action</p>

            @if(!$signedRequestLetterReady)
                <h2>Complete the signed request document</h2>
                <p>
                    Print the generated Borrowing Request Letter, obtain the required wet signatures,
                    then upload the fully signed scan{{ $ptcRequired ? ' and the Permission to Conduct Letter' : '' }}.
                </p>
            @elseif(!$ptcReady)
                <h2>Upload the required Permission to Conduct Letter</h2>
                <p>
                    Your signed Borrowing Request Letter is already uploaded. Add the Permission to Conduct Letter to continue.
                </p>
            @else
                <h2>Ready to submit to SPMU</h2>
                <p>
                    The required documents are complete. Review the request if needed, then submit it to SPMU.
                </p>
            @endif

            <div class="inline-actions top-gap">
                @if(!$signedRequestLetterReady && $draftRequestLetter)
                    <a
                        class="button primary ui-pressable"
                        href="{{ route('documents.download', $draftRequestLetter) }}"
                        target="_blank"
                        rel="noopener"
                    >
                        View / Print Request Letter
                    </a>
                @elseif(!$signedRequestLetterReady)
                    <form method="post" action="{{ route('requests.recover-draft-document', $borrowingRequest) }}">
                        @csrf
                        <button class="button primary ui-pressable" type="submit">
                            Generate Request Letter
                        </button>
                    </form>
                @endif

                @if(!$submissionReady)
                    <a
                        class="button secondary ui-pressable"
                        href="{{ route('requests.edit', $borrowingRequest) }}"
                    >
                        {{ $signedRequestLetterReady ? 'Upload Required Document' : 'Upload Signed Scan' }}
                    </a>
                @else
                    <a
                        class="button secondary ui-pressable"
                        href="{{ route('requests.edit', $borrowingRequest) }}"
                    >
                        Edit Request
                    </a>

                    <form method="post" action="{{ route('requests.submit', $borrowingRequest) }}">
                        @csrf
                        <button class="button primary ui-pressable" type="submit">
                            Submit to SPMU
                        </button>
                    </form>
                @endif
            </div>

            @if(!$submissionReady)
                <p class="meta top-gap">
                    Submit to SPMU becomes available after all required scanned documents are uploaded.
                </p>
            @endif
        </div>
    </div>
</section>
@endif

<x-request-progress-tracker :request="$borrowingRequest" :show-current-status="false" />

@if($isUnderSpmuReview)
<section class="content-area spmu-verification-workspace" data-spmu-verification-workspace>
    <div class="spmu-verification-grid">
        <x-document-review-viewer
            :file="$requestLetterDoc?->file"
            title="Inspect the signed Borrowing Request Letter"
        />

        <article class="card spmu-checklist-panel">
            @if($canDecide)
                <div class="card-header">
                    <div>
                        <p class="eyebrow">SPMU Head / Admin</p>
                        <h2>Review and decide</h2>
                    </div>
                    <span class="status-badge status-info">For approval</span>
                </div>

                <p class="meta spmu-review-summary">
                    Verify the signed documents, request details, dates, quantities, and system availability.
                    <strong>Verify &amp; Approve</strong> reserves the approved quantities for pickup; it does not physically issue the items.
                </p>

                @if($v->represents_student_activity)
                    <div class="spmu-supporting-document">
                        <div>
                            <strong>Permission to Conduct Letter</strong>
                            <small>Required for this student activity / organization request.</small>
                        </div>
                        @if($permissionToConductDoc)
                            <a class="button secondary small ui-pressable" href="{{ route('files.show', $permissionToConductDoc->file, false) }}" target="_blank" rel="noopener">View Attachment</a>
                        @else
                            <span class="status-badge status-danger">Missing</span>
                        @endif
                    </div>
                @endif

                <form
                    method="post"
                    action="{{ route('approvals.decide', $borrowingRequest) }}"
                    class="spmu-verification-form top-gap"
                    data-verification-form
                    data-required-supporting-present="{{ ($requestLetterDoc && (!$v->represents_student_activity || $permissionToConductDoc)) ? '1' : '0' }}"
                >
                    @csrf

                    <input type="hidden" name="decision" value="" data-verification-decision>
                    <input type="hidden" name="remarks" value="{{ old('remarks') }}" data-verification-remarks>

                    <div class="spmu-checklist">
                        <label class="spmu-check-row">
                            <input type="checkbox" name="details_complete" value="1" data-verification-check @checked(old('details_complete'))>
                            <span><strong>Request details match the signed letter</strong><small>Borrower, event, dates, location, items, and quantities are consistent.</small></span>
                        </label>
                        <label class="spmu-check-row">
                            <input type="checkbox" name="signatures_present" value="1" data-verification-check @checked(old('signatures_present'))>
                            <span><strong>Required wet signatures are present</strong><small>Required handwritten signatures / endorsements are visible on the scan.</small></span>
                        </label>
                        <label class="spmu-check-row">
                            <input type="checkbox" name="document_readable" value="1" data-verification-check @checked(old('document_readable'))>
                            <span><strong>Uploaded document is clear and readable</strong><small>The scan can be verified without guessing or missing content.</small></span>
                        </label>
                        <label class="spmu-check-row">
                            <input type="checkbox" name="availability_verified" value="1" data-verification-check @checked(old('availability_verified'))>
                            <span><strong>Requested quantities and availability checked</strong><small>Current inventory and selected schedule can support the signed requested quantities.</small></span>
                        </label>
                    </div>

                    <p class="field-error top-gap" data-verification-inline-error hidden></p>

                    <div class="spmu-review-footer">
                        <div class="spmu-decision-actions">
                            <button
                                class="button primary ui-pressable"
                                type="button"
                                data-decision-trigger="APPROVED"
                                data-approve-button
                                disabled
                            >
                                Verify &amp; Approve
                            </button>

                            <button
                                class="button secondary ui-pressable"
                                type="button"
                                data-decision-trigger="RETURNED_FOR_REVISION"
                            >
                                Return for Revision
                            </button>

                            <button
                                class="button danger ui-pressable"
                                type="button"
                                data-decision-trigger="REJECTED"
                            >
                                Reject
                            </button>
                        </div>

                        <p class="meta spmu-review-footer__note">
                            After approval, the Action Officer handles pickup scheduling, required physical documents, item preparation, and release.
                        </p>
                    </div>
                </form>

                <dialog class="spmu-confirm-dialog" data-verification-confirm-dialog>
                    <div class="spmu-confirm-dialog__surface">
                        <div class="spmu-confirm-dialog__icon" aria-hidden="true">?</div>

                        <div>
                            <h2 data-confirm-title>Confirm decision</h2>
                            <p class="meta" data-confirm-message></p>

                            <div class="spmu-dialog-remarks" data-confirm-remarks-wrap hidden>
                                <label for="spmu-decision-remarks" data-confirm-remarks-label>Remarks</label>
                                <textarea
                                    id="spmu-decision-remarks"
                                    rows="5"
                                    maxlength="2000"
                                    data-confirm-remarks
                                    placeholder="Enter the reason or instructions for the borrower."
                                ></textarea>
                                <small data-confirm-remarks-help></small>
                                <p class="field-error" data-confirm-remarks-error hidden></p>
                            </div>
                        </div>

                        <div class="spmu-confirm-dialog__actions">
                            <button class="button secondary ui-pressable" type="button" data-confirm-cancel>Go Back</button>
                            <button class="button primary ui-pressable" type="button" data-confirm-submit>Confirm</button>
                        </div>
                    </div>
                </dialog>
            @else
                <div class="card-header">
                    <div>
                        <p class="eyebrow">SPMU review</p>
                        <h2>Waiting for Head decision</h2>
                    </div>
                </div>

                <div class="empty-state">
                    <strong>This request is awaiting SPMU Head approval.</strong>
                    <span>Operational processing by the Action Officer starts only after SPMU Head approval and inventory allocation for pickup.</span>
                </div>
            @endif
        </article>
    </div>
</section>
@endif

@if($isUnderSpmuReview)
<section class="content-grid two spmu-review-secondary-grid">
    <article class="card review-column-card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Request details</p>
                <h2>Borrowing information</h2>
            </div>
        </div>

        <div class="review-scroll-area review-detail-scroll">
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
        </div>
    </article>

    <article class="card review-column-card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Requested property</p>
                <h2>Items and quantities</h2>
            </div>
        </div>

        <div class="table-wrap review-scroll-area review-table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Requested</th>
                        <th>{{ $requestIsCompleted ? 'Approved' : 'Approved / Reserved' }}</th>
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
@else
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

        @if(!$isBorrower || !$requestIsCompleted)
            <p class="meta">
                The uploaded letter is evidence/notice of the institutionally approved request. It does not reserve inventory until SPMU verifies and approves it in the system.
            </p>
        @endif
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
                        <th>{{ $requestIsCompleted ? 'Approved' : 'Approved / Reserved' }}</th>
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
@endif

@if($borrowingRequest->custody)
<section class="content-area">
    <div class="action-panel action-neutral">
        <div>
            <p class="eyebrow">My Borrowings</p>
            <h2>{{ $requestIsCompleted ? 'View the completed borrowing record' : 'Continue to pickup / custody details' }}</h2>
            <p>
                My Borrowings contains the pickup schedule, issued and returned quantities, outstanding items,
                linen/laundry progress, and final return reconciliation. Those operational details are not repeated on this request page.
            </p>
        </div>

        <a
            class="button primary ui-pressable"
            href="{{ route('custody.show', $borrowingRequest->custody) }}"
        >
            View My Borrowing
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
    <article class="card request-cancel-card" data-request-cancel-workspace>
        <div class="card-header">
            <div>
                <p class="eyebrow">Request action</p>
                <h2>Cancel this request</h2>
            </div>
        </div>

        <p class="meta">
            You may cancel this request until the items are physically released to you.
            @if($borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease)
                Any approved but unreleased allocation will return to Available inventory. Any active pickup window, Borrower Slip, and Gate Pass prepared for this request will no longer be valid.
            @endif
        </p>

        <button
            class="button danger ui-pressable"
            type="button"
            data-request-cancel-trigger
        >
            Cancel Request
        </button>

        <form
            method="post"
            action="{{ route('requests.cancel', $borrowingRequest) }}"
            data-request-cancel-form
        >
            @csrf
            <input type="hidden" name="reason" value="" data-request-cancel-reason>
        </form>

        <dialog class="spmu-confirm-dialog" data-request-cancel-dialog>
            <div class="spmu-confirm-dialog__surface">
                <div class="spmu-confirm-dialog__icon spmu-confirm-dialog__icon--danger" aria-hidden="true">!</div>

                <div>
                    <h2>Cancel this borrowing request?</h2>
                    <p class="meta">
                        This action takes effect immediately because the items have not been physically released yet.
                        @if($borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease)
                            The approved allocation will be released back to Available inventory and any pending pickup documents will be invalidated.
                        @endif
                    </p>

                    <div class="spmu-dialog-remarks">
                        <label for="borrower-cancellation-reason">Cancellation reason *</label>
                        <textarea
                            id="borrower-cancellation-reason"
                            rows="5"
                            maxlength="1000"
                            data-request-cancel-reason-field
                            placeholder="Example: The activity was cancelled or the items are no longer needed."
                        ></textarea>
                        <small>Provide a clear reason. It will be saved in the request history.</small>
                        <p class="field-error" data-request-cancel-error hidden></p>
                    </div>
                </div>

                <div class="spmu-confirm-dialog__actions">
                    <button class="button secondary ui-pressable" type="button" data-request-cancel-back>Go Back</button>
                    <button class="button danger ui-pressable" type="button" data-request-cancel-confirm>Yes, Cancel Request</button>
                </div>
            </div>
        </dialog>
    </article>
</section>
@endif

@unless($isBorrower)
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
@endunless

@if($isUnderSpmuReview)
<style>
.spmu-verification-grid {
    display: grid;
    width: 100%;
    min-width: 0;
    grid-template-columns: minmax(0, 1.35fr) minmax(400px, .65fr);
    gap: 24px;
    align-items: stretch;
}

.spmu-verification-grid > * {
    min-width: 0;
}

.spmu-document-panel,
.spmu-checklist-panel,
.spmu-verification-grid > .scanned-document-card {
    width: 100%;
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
}

/* Keep the document preview and decision panel aligned as one review row. */
.spmu-verification-grid > .scanned-document-card,
.spmu-checklist-panel {
    height: 100%;
    align-self: stretch;
}

.spmu-verification-grid > .scanned-document-card {
    display: flex;
    flex-direction: column;
}

.spmu-verification-grid > .scanned-document-card .scanned-pdf-stage {
    flex: 1 1 auto;
    height: auto;
    min-height: clamp(620px, 70vh, 820px);
}

.spmu-verification-grid > .scanned-document-card .scanned-image-viewer {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    flex-direction: column;
}

.spmu-verification-grid > .scanned-document-card .scanned-image-stage {
    flex: 1 1 auto;
    height: auto;
    min-height: clamp(620px, 70vh, 820px);
}

.spmu-checklist-panel {
    display: flex;
    flex-direction: column;
}

.spmu-verification-form {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    flex-direction: column;
}

.spmu-review-summary {
    margin-bottom: 2px;
}

.spmu-review-footer {
    margin-top: auto;
    padding-top: 18px;
    border-top: 1px solid var(--border, #d7dee8);
}

.spmu-review-secondary-grid {
    align-items: stretch;
}

.review-column-card {
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.review-scroll-area {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
}

.review-detail-scroll,
.review-table-scroll {
    max-height: clamp(420px, 48vh, 620px);
}

.review-detail-scroll {
    padding-right: 4px;
}

.review-table-scroll table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface-subtle, #f5f7fb);
}

.review-detail-scroll .detail-list {
    margin-bottom: 0;
}

.spmu-review-footer__note {
    margin: 12px 0 0;
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
    margin-top: 0;
}

.spmu-decision-actions .button {
    width: 100%;
}

.spmu-decision-actions .button:disabled {
    opacity: .48;
    cursor: not-allowed;
    transform: none;
}

.spmu-dialog-remarks {
    display: grid;
    gap: 8px;
    margin-top: 16px;
}

.spmu-dialog-remarks textarea {
    width: 100%;
    resize: vertical;
}

.field-error {
    margin: 0;
    color: #b42318;
    font-size: .875rem;
}

.spmu-confirm-dialog {
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    right: auto !important;
    bottom: auto !important;
    transform: translate(-50%, -50%);
    width: min(520px, calc(100vw - 32px));
    max-height: calc(100dvh - 32px);
    margin: 0 !important;
    padding: 0;
    overflow: auto;
    border: 0;
    border-radius: 18px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .25);
    z-index: 10000;
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

.spmu-confirm-dialog__icon--danger {
    background: #feeceb;
    color: #b42318;
}

.request-cancel-card {
    display: grid;
    gap: 14px;
}

.request-cancel-card > .button {
    justify-self: start;
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

@media (max-width: 1180px) {
    .spmu-verification-grid {
        grid-template-columns: 1fr;
    }

    .spmu-review-secondary-grid {
        grid-template-columns: 1fr;
    }

    .review-detail-scroll,
    .review-table-scroll {
        max-height: none;
        overflow: visible;
    }

    .spmu-verification-grid > .scanned-document-card,
    .spmu-checklist-panel {
        height: auto;
    }

    .spmu-verification-grid > .scanned-document-card .scanned-pdf-stage,
    .spmu-verification-grid > .scanned-document-card .scanned-image-stage {
        min-height: 520px;
        height: 64vh;
    }

    .spmu-review-footer {
        margin-top: 18px;
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

    if (!workspace) return;

    const form = workspace.querySelector('[data-verification-form]');
    const dialog = workspace.querySelector('[data-verification-confirm-dialog]');

    if (!form || !dialog) return;

    const checks = [...form.querySelectorAll('[data-verification-check]')];
    const approveButton = form.querySelector('[data-approve-button]');
    const decisionInput = form.querySelector('[data-verification-decision]');
    const remarksInput = form.querySelector('[data-verification-remarks]');
    const inlineError = form.querySelector('[data-verification-inline-error]');
    const triggers = [...form.querySelectorAll('[data-decision-trigger]')];

    const confirmTitle = dialog.querySelector('[data-confirm-title]');
    const confirmMessage = dialog.querySelector('[data-confirm-message]');
    const confirmSubmit = dialog.querySelector('[data-confirm-submit]');
    const confirmCancel = dialog.querySelector('[data-confirm-cancel]');
    const remarksWrap = dialog.querySelector('[data-confirm-remarks-wrap]');
    const remarksLabel = dialog.querySelector('[data-confirm-remarks-label]');
    const remarksField = dialog.querySelector('[data-confirm-remarks]');
    const remarksHelp = dialog.querySelector('[data-confirm-remarks-help]');
    const remarksError = dialog.querySelector('[data-confirm-remarks-error]');

    const requiredSupportingPresent = form.dataset.requiredSupportingPresent === '1';
    let pendingDecision = '';

    const decisionCopy = {
        APPROVED: {
            title: 'Verify and approve this request?',
            message: 'The request will be approved and the approved quantities will be allocated/held for this borrower. They are not yet physically issued. The SPMU Action Officer will schedule pickup and complete the physical handover.',
            confirm: 'Yes, Verify & Approve',
            tone: 'primary',
        },
        RETURNED_FOR_REVISION: {
            title: 'Return this request for revision?',
            message: 'The borrower will receive your remarks and must correct the request or supporting documents before resubmitting.',
            confirm: 'Return for Revision',
            tone: 'secondary',
        },
        REJECTED: {
            title: 'Reject this borrowing request?',
            message: 'This closes the request as rejected. The borrower will receive the reason you provide below.',
            confirm: 'Reject Request',
            tone: 'danger',
        },
    };

    const checklistComplete = () =>
        requiredSupportingPresent &&
        checks.length === 4 &&
        checks.every((checkbox) => checkbox.checked);

    const updateApproveState = () => {
        if (approveButton) approveButton.disabled = !checklistComplete();
    };

    const showInlineError = (message = '') => {
        if (!inlineError) return;
        inlineError.textContent = message;
        inlineError.hidden = message === '';
    };

    const clearRemarksError = () => {
        if (!remarksError) return;
        remarksError.textContent = '';
        remarksError.hidden = true;
    };

    const configureRemarks = (decision) => {
        const needsRemarks = decision !== 'APPROVED';
        remarksWrap.hidden = !needsRemarks;
        clearRemarksError();

        if (!needsRemarks) {
            remarksField.value = '';
            return;
        }

        remarksField.value = remarksInput.value || '';

        if (decision === 'RETURNED_FOR_REVISION') {
            remarksLabel.textContent = 'Revision instructions *';
            remarksHelp.textContent = 'State exactly what the borrower needs to correct before resubmitting.';
            remarksField.placeholder = 'Example: Upload a clearer signed BR Letter and correct the requested quantity.';
        } else {
            remarksLabel.textContent = 'Reason for rejection *';
            remarksHelp.textContent = 'State a clear reason that can be shown to the borrower.';
            remarksField.placeholder = 'Example: The request cannot be approved for the selected activity or schedule.';
        }
    };

    const openConfirmation = (decision) => {
        const copy = decisionCopy[decision];
        pendingDecision = decision;
        decisionInput.value = decision;
        confirmTitle.textContent = copy.title;
        confirmMessage.textContent = copy.message;
        confirmSubmit.textContent = copy.confirm;
        confirmSubmit.className = `button ${copy.tone} ui-pressable`;
        configureRemarks(decision);

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
            if (decision !== 'APPROVED') {
                window.setTimeout(() => remarksField.focus(), 0);
            }
            return;
        }

        if (decision === 'APPROVED') {
            if (window.confirm(`${copy.title}\n\n${copy.message}`)) form.submit();
            return;
        }

        const fallbackRemarks = window.prompt(
            decision === 'REJECTED'
                ? 'Enter the reason for rejection:'
                : 'Enter the revision instructions:'
        );

        if (fallbackRemarks && fallbackRemarks.trim()) {
            remarksInput.value = fallbackRemarks.trim();
            form.submit();
        }
    };

    checks.forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            showInlineError('');
            updateApproveState();
        });
    });

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const decision = trigger.dataset.decisionTrigger;
            showInlineError('');

            if (decision === 'APPROVED' && !checklistComplete()) {
                showInlineError(
                    requiredSupportingPresent
                        ? 'Complete all four verification checks before approving.'
                        : 'The required supporting document is missing. Approval is unavailable until the required document is attached.'
                );
                return;
            }

            openConfirmation(decision);
        });
    });

    confirmCancel?.addEventListener('click', () => {
        pendingDecision = '';
        decisionInput.value = '';
        clearRemarksError();
        dialog.close();
    });

    confirmSubmit?.addEventListener('click', () => {
        if (!pendingDecision) return;

        if (pendingDecision !== 'APPROVED') {
            const value = remarksField.value.trim();

            if (!value) {
                remarksError.textContent =
                    pendingDecision === 'REJECTED'
                        ? 'Enter the reason for rejection before continuing.'
                        : 'Enter revision instructions before continuing.';
                remarksError.hidden = false;
                remarksField.focus();
                return;
            }

            remarksInput.value = value;
        } else {
            remarksInput.value = '';
        }

        confirmSubmit.disabled = true;
        form.submit();
    });

    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        pendingDecision = '';
        decisionInput.value = '';
        clearRemarksError();
        dialog.close();
    });

    remarksField?.addEventListener('input', clearRemarksError);
    updateApproveState();
})();


(() => {
    const workspace = document.querySelector('[data-request-cancel-workspace]');
    if (!workspace) return;

    const trigger = workspace.querySelector('[data-request-cancel-trigger]');
    const dialog = workspace.querySelector('[data-request-cancel-dialog]');
    const form = workspace.querySelector('[data-request-cancel-form]');
    const hiddenReason = workspace.querySelector('[data-request-cancel-reason]');
    const reasonField = workspace.querySelector('[data-request-cancel-reason-field]');
    const error = workspace.querySelector('[data-request-cancel-error]');
    const back = workspace.querySelector('[data-request-cancel-back]');
    const confirm = workspace.querySelector('[data-request-cancel-confirm]');

    if (!trigger || !dialog || !form || !hiddenReason || !reasonField || !confirm) return;

    const clearError = () => {
        if (!error) return;
        error.textContent = '';
        error.hidden = true;
    };

    const closeDialog = () => {
        clearError();
        if (dialog.open && typeof dialog.close === 'function') dialog.close();
    };

    trigger.addEventListener('click', () => {
        clearError();
        reasonField.value = '';

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
            window.setTimeout(() => reasonField.focus(), 0);
            return;
        }

        const fallbackReason = window.prompt('Enter the cancellation reason:');
        if (fallbackReason && fallbackReason.trim()) {
            hiddenReason.value = fallbackReason.trim();
            form.submit();
        }
    });

    back?.addEventListener('click', closeDialog);

    confirm.addEventListener('click', () => {
        const reason = reasonField.value.trim();
        if (!reason) {
            if (error) {
                error.textContent = 'Please provide a cancellation reason.';
                error.hidden = false;
            }
            reasonField.focus();
            return;
        }

        hiddenReason.value = reason;
        confirm.disabled = true;
        form.submit();
    });

    reasonField.addEventListener('input', clearError);

    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeDialog();
    });
})();

</script>
@endif
@endsection
