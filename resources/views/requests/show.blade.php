@extends('layouts.app', ['title' => $borrowingRequest->request_no])

@section('content')

@php
    $version = $borrowingRequest->currentVersion;

    /*
    |--------------------------------------------------------------------------
    | Effective operational status
    |--------------------------------------------------------------------------
    |
    | Once physical custody exists, custody status is the most useful status
    | to display. Legacy request states are retained by the backend for
    | compatibility, but the Borrower UI below presents the current SPMU-only
    | workflow terminology.
    |
    */

    $custody = $borrowingRequest->custody;
    $custodyStatus = $custody?->status;

    $displayStatus = match($custodyStatus) {
        'ACTIVE' => 'ACTIVE',
        'PARTIALLY_RETURNED' => 'PARTIALLY_RETURNED',
        'OVERDUE' => 'OVERDUE',
        'EARLY_RETURN' => 'EARLY_RETURN',
        'INCIDENT_OPEN' => 'INCIDENT_OPEN',
        'OBLIGATION_OPEN' => 'OBLIGATION_OPEN',
        'CLOSED' => 'CLOSED',
        default => $borrowingRequest->status,
    };

    $displayStatusLabel = match($custodyStatus) {
        'ACTIVE' => 'Released',
        'PARTIALLY_RETURNED' => 'Partially Returned',
        'OVERDUE' => 'Overdue',
        'EARLY_RETURN' => 'Early Return',
        'INCIDENT_OPEN' => 'Incident Open',
        'OBLIGATION_OPEN' => 'Obligation Open',
        'CLOSED' => 'Returned',
        default => null,
    };

    $isBorrower =
        session('active_workspace') === 'BORROWER'
        && $borrowingRequest->borrower_user_id === auth()->id();

    /*
     * Borrower-facing compatibility labels.
     *
     * Historical UNDER_GSU / UNDER_VPAF records are shown as "Under Review".
     * Historical FINAL_APPROVED_AWAITING_DOWNLOAD records are shown as
     * "Approved" because approved-letter download is no longer a workflow gate.
     */
    $borrowerDisplayStatus = $displayStatus;
    $borrowerDisplayStatusLabel = $displayStatusLabel;

    if (!$custodyStatus) {
        $borrowerDisplayStatus = match($borrowingRequest->status) {
            App\Enums\RequestStatus::UnderGsu,
            App\Enums\RequestStatus::UnderVpaf
                => App\Enums\RequestStatus::UnderSpmu,

            App\Enums\RequestStatus::FinalApprovedAwaitingDownload
                => App\Enums\RequestStatus::ApprovedReadyForRelease,

            default => $borrowingRequest->status,
        };

        $borrowerDisplayStatusLabel = match($borrowingRequest->status) {
            App\Enums\RequestStatus::UnderSpmu => 'Under SPMU Review',

            App\Enums\RequestStatus::UnderGsu,
            App\Enums\RequestStatus::UnderVpaf => 'Under Review',

            App\Enums\RequestStatus::FinalApprovedAwaitingDownload,
            App\Enums\RequestStatus::ApprovedReadyForRelease => 'Approved',

            default => null,
        };
    }

    $hasActiveDraftPreview = $version->documents->contains(
        fn($document) =>
            $document->document_type === 'REQUEST_LETTER'
            && $document->status === 'DRAFT'
    );

    $draftPreview = $version->documents->first(
        fn($document) =>
            $document->document_type === 'REQUEST_LETTER'
            && $document->status === 'DRAFT'
    );

    $documentNames = [
        'REQUEST_LETTER' => 'Borrowing Request Letter',
        'BORROWER_SLIP' => "Borrower’s Slip",
        'GATE_PASS' => 'Gate Pass',
        'LAUNDRY_FORM' => 'Laundry Form',
        'BILLING_STATEMENT' => 'Billing Statement',
    ];

    /*
    |--------------------------------------------------------------------------
    | Borrower supporting documents
    |--------------------------------------------------------------------------
    |
    | Batch 1 introduced RequestVersion::supportingDocuments(). These guards
    | allow the UI file to remain render-safe while the workflow developer
    | connects the upload/download routes.
    |
    */

    $supportingDocuments = collect();
    $supportingDocumentModelReady = method_exists($version, 'supportingDocuments');

    if ($isBorrower && $supportingDocumentModelReady) {
        $supportingDocuments = $version->relationLoaded('supportingDocuments')
            ? $version->supportingDocuments
                ->where('status', 'ACTIVE')
                ->sortByDesc('uploaded_at')
                ->values()
            : $version->supportingDocuments()
                ->where('status', 'ACTIVE')
                ->with('file')
                ->latest('uploaded_at')
                ->get();
    }

    $signedRequestLetter = $supportingDocuments->first(
        fn($document) => $document->document_type === 'SIGNED_REQUEST_LETTER'
    );

    $ptcDocument = $supportingDocuments->first(
        fn($document) => $document->document_type === 'PTC'
    );

    $hasRequiredSupportingDocuments =
        (bool) $signedRequestLetter
        && (bool) $ptcDocument;

    $supportingUploadRouteReady =
        \Illuminate\Support\Facades\Route::has('requests.supporting-documents.store');

    $supportingDownloadRouteName =
        \Illuminate\Support\Facades\Route::has('requests.supporting-documents.download')
            ? 'requests.supporting-documents.download'
            : (
                \Illuminate\Support\Facades\Route::has('request-supporting-documents.download')
                    ? 'request-supporting-documents.download'
                    : null
            );

    $supportingDownloadRouteReady =
        (bool) $supportingDownloadRouteName;

    $borrowerGeneratedDocuments = $version->documents
        ->reject(
            fn($document) =>
                $document->document_type === 'APPROVED_REQUEST_LETTER'
        )
        ->sortByDesc('generated_at');

    $spmuApprovalSteps = $version->approvalSteps
        ->filter(function ($step) {
            $stage = $step->stage_code instanceof \BackedEnum
                ? $step->stage_code->value
                : (string) $step->stage_code;

            return $stage === 'SPMU';
        })
        ->sortBy('sequence_no')
        ->values();

    $requestStatusValue =
        $borrowingRequest->status instanceof \BackedEnum
            ? $borrowingRequest->status->value
            : (string) $borrowingRequest->status;

    $requestIsUnderReview = in_array(
        $requestStatusValue,
        [
            'SIGNED',
            'SUBMITTED',
            'UNDER_SPMU',
            'UNDER_GSU',
            'UNDER_VPAF',
        ],
        true
    );

    $requestIsApproved = in_array(
        $requestStatusValue,
        [
            'FINAL_APPROVED_AWAITING_DOWNLOAD',
            'APPROVED_READY_FOR_RELEASE',
        ],
        true
    ) || (bool) $custody;

    $reviewProgressStatus = match($requestStatusValue) {
        'RETURNED_FOR_REVISION' => 'RETURNED_FOR_REVISION',
        'REJECTED' => 'REJECTED',
        'CANCELLED' => 'CANCELLED',
        'EXPIRED' => 'EXPIRED',
        default => $requestIsApproved ? 'APPROVED' : 'PENDING',
    };

    $reviewProgressLabel = match($requestStatusValue) {
        'RETURNED_FOR_REVISION' => 'Returned for Revision',
        'REJECTED' => 'Rejected',
        'CANCELLED' => 'Cancelled',
        'EXPIRED' => 'Closed',
        default => $requestIsApproved
            ? 'Approved'
            : ($requestIsUnderReview ? 'Under SPMU Review' : 'Waiting for submission'),
    };
@endphp


@if($isBorrower)

{{-- ========================================================= --}}
{{-- BORROWER REQUEST DETAILS                                  --}}
{{-- ========================================================= --}}

<style>
    .borrower-request-docs {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .borrower-document-slot {
        display: grid;
        gap: 10px;
        padding: 15px;
        background: var(--surface-subtle);
        border: 1px solid var(--border);
        border-radius: var(--radius);
    }

    .borrower-document-slot.is-complete {
        border-left: 3px solid var(--success);
    }

    .borrower-document-slot.is-missing {
        border-left: 3px solid var(--warning);
    }

    .borrower-document-slot-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .borrower-document-slot-header > div {
        min-width: 0;
    }

    .borrower-document-slot h3,
    .borrower-document-slot p {
        margin: 0;
    }

    .borrower-document-slot p,
    .borrower-document-slot small {
        color: var(--text-muted);
    }

    .borrower-document-slot form {
        display: grid;
        gap: 8px;
    }

    .borrower-document-slot input[type="file"] {
        width: 100%;
    }

    .borrower-required-note {
        margin-top: 12px;
    }

    .borrower-submit-checks {
        display: grid;
        gap: 7px;
        margin: 13px 0 0;
        padding: 0;
        list-style: none;
    }

    .borrower-submit-checks li {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--text-muted);
        font-size: 12px;
    }

    .borrower-submit-checks strong {
        color: var(--heading);
    }

    .borrower-check-mark {
        display: grid;
        width: 22px;
        height: 22px;
        flex: 0 0 22px;
        place-items: center;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 850;
    }

    .borrower-check-mark.is-complete {
        color: var(--success);
        background: var(--success-subtle, #edf8f1);
        border: 1px solid var(--success-border, #b9ddc5);
    }

    .borrower-check-mark.is-missing {
        color: var(--warning);
        background: var(--warning-subtle, #fff8e7);
        border: 1px solid var(--warning-border, #ead8a7);
    }

    .borrower-readonly-condition {
        display: inline-flex;
        align-items: center;
        min-height: 26px;
        padding: 3px 8px;
        border: 1px solid var(--border);
        border-radius: 999px;
        color: var(--text);
        background: var(--surface-subtle);
        font-size: 11px;
        font-weight: 750;
        white-space: nowrap;
    }

    @media (max-width: 760px) {
        .borrower-request-docs {
            grid-template-columns: 1fr;
        }

        .borrower-document-slot-header {
            flex-direction: column;
        }
    }
</style>


{{-- ========================================================= --}}
{{-- PAGE HEADING                                              --}}
{{-- ========================================================= --}}

<section class="page-heading request-detail-heading">
    <div>
        <p class="eyebrow">
            Request {{ $borrowingRequest->request_no }}
            · Version {{ $version->version_no }}
        </p>

        <h1>{{ $version->purpose_event ?: 'Borrowing request' }}</h1>

        <p class="heading-status">
            <x-status-badge
                :status="$borrowerDisplayStatus"
                :label="$borrowerDisplayStatusLabel"
            />

            <span>
                @if($custody?->released_at)
                    Released {{ $custody->released_at->format('d M Y, g:i A') }}
                @else
                    Updated {{ $borrowingRequest->updated_at->format('d M Y, g:i A') }}
                @endif
            </span>
        </p>
    </div>

    <div class="actions">
        @if(in_array(
            $borrowingRequest->status,
            [
                App\Enums\RequestStatus::Draft,
                App\Enums\RequestStatus::ReturnedForRevision
            ],
            true
        ))
            <a
                class="button secondary ui-pressable"
                href="{{ route('requests.edit', $borrowingRequest) }}"
            >
                {{
                    $borrowingRequest->status === App\Enums\RequestStatus::ReturnedForRevision
                        ? 'Revise request'
                        : 'Edit draft'
                }}
            </a>
        @endif

        <a
            class="button ghost"
            href="{{ route('requests.index') }}"
        >
            Back to My Requests
        </a>
    </div>
</section>


{{-- ========================================================= --}}
{{-- CURRENT ACTION                                            --}}
{{-- ========================================================= --}}

<section class="content-area">

    @switch($borrowingRequest->status)

        @case(App\Enums\RequestStatus::Draft)

            <div class="action-panel action-primary">
                <div>
                    <p class="eyebrow">Action required</p>
                    <h2>Complete the required documents</h2>

                    <p>
                        Download and print the Borrowing Request Letter, obtain the
                        required handwritten signatures, then upload the fully signed
                        letter together with the Permission to Conduct (PTC) Letter.
                    </p>
                </div>

                <div class="actions">
                    @if($draftPreview)
                        <a
                            class="button secondary ui-pressable"
                            href="{{ route('documents.download', $draftPreview) }}"
                        >
                            Download / Print BR Letter
                        </a>
                    @endif

                    <a
                        class="button primary ui-pressable"
                        href="#required-documents"
                    >
                        Required documents
                    </a>
                </div>
            </div>

            @break


        @case(App\Enums\RequestStatus::ReturnedForRevision)

            <div class="action-panel action-warning">
                <div>
                    <p class="eyebrow">Action required</p>
                    <h2>Revise this request</h2>

                    <p>
                        Review the SPMU remarks, correct the request, and save the
                        revised version. Generate and complete the required physical
                        documents again before resubmitting.
                    </p>
                </div>

                <a
                    class="button primary ui-pressable"
                    href="{{ route('requests.edit', $borrowingRequest) }}"
                >
                    Revise request
                </a>
            </div>

            @break


        @case(App\Enums\RequestStatus::FinalApprovedAwaitingDownload)
        @case(App\Enums\RequestStatus::ApprovedReadyForRelease)

            @switch($custodyStatus)

                @case('ACTIVE')
                    <div class="action-panel action-success">
                        <div>
                            <p class="eyebrow">Physical release completed</p>
                            <h2>Items released</h2>

                            <p>
                                Your approved items have been physically released by
                                SPMU and are now under your custody. Review the actual
                                issued quantities and return deadline.
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button primary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                Open my borrowing
                            </a>
                        @endif
                    </div>
                    @break

                @case('PARTIALLY_RETURNED')
                    <div class="action-panel action-warning">
                        <div>
                            <p class="eyebrow">Return in progress</p>
                            <h2>Partially returned</h2>

                            <p>
                                Some released quantities have already been returned.
                                Review the remaining quantities still under your custody.
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button primary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                Review remaining items
                            </a>
                        @endif
                    </div>
                    @break

                @case('OVERDUE')
                    <div class="action-panel action-warning">
                        <div>
                            <p class="eyebrow">Return required</p>
                            <h2>Borrowing overdue</h2>

                            <p>
                                The return deadline has passed. Review your borrowing
                                record and coordinate the physical return with SPMU.
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button primary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                Review overdue borrowing
                            </a>
                        @endif
                    </div>
                    @break

                @case('EARLY_RETURN')
                    <div class="action-panel action-primary">
                        <div>
                            <p class="eyebrow">Return coordination</p>
                            <h2>Early return in progress</h2>

                            <p>
                                An early-return process is recorded for this borrowing.
                                Inventory quantities change only after SPMU physically
                                receives and inspects the items.
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button primary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                Open return details
                            </a>
                        @endif
                    </div>
                    @break

                @case('INCIDENT_OPEN')
                    <div class="action-panel action-warning">
                        <div>
                            <p class="eyebrow">Accountability review</p>
                            <h2>Incident remains open</h2>

                            <p>
                                An incident or accountability issue remains open for
                                this borrowing. Review the custody record for the
                                latest details and required action.
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button primary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                Review incident
                            </a>
                        @endif
                    </div>
                    @break

                @case('OBLIGATION_OPEN')
                    <div class="action-panel action-warning">
                        <div>
                            <p class="eyebrow">Items returned</p>
                            <h2>Outstanding obligation remains</h2>

                            <p>
                                The physical items have been returned, but an
                                outstanding obligation still requires completion
                                before the custody record can be closed.
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button primary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                Review obligations
                            </a>
                        @endif
                    </div>
                    @break

                @case('CLOSED')
                    <div class="action-panel action-success">
                        <div>
                            <p class="eyebrow">Borrowing completed</p>
                            <h2>Items returned</h2>

                            <p>
                                This borrowing has been completed and the custody
                                transaction is now closed.
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button secondary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                View completed borrowing
                            </a>
                        @endif
                    </div>
                    @break

                @default
                    <div class="action-panel action-success">
                        <div>
                            <p class="eyebrow">Approved request</p>
                            <h2>Ready for SPMU release processing</h2>

                            <p>
                                {{
                                    $custody
                                        ? 'SPMU is preparing the release record. Open your borrowing to review the latest release status.'
                                        : 'Your request is approved. SPMU will prepare the release record and pickup instructions.'
                                }}
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button primary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                View release status
                            </a>
                        @endif
                    </div>

            @endswitch

            @break


        @case(App\Enums\RequestStatus::UnderSpmu)
        @case(App\Enums\RequestStatus::UnderGsu)
        @case(App\Enums\RequestStatus::UnderVpaf)

            <div class="action-panel action-neutral">
                <div>
                    <p class="eyebrow">No action required</p>
                    <h2>Your request is under review</h2>

                    <p>
                        Your request has been submitted for review. You will be
                        notified if SPMU returns it for revision or records a decision.
                    </p>
                </div>

                <x-status-badge
                    :status="$borrowerDisplayStatus"
                    :label="$borrowerDisplayStatusLabel"
                />
            </div>

            @break


        @case(App\Enums\RequestStatus::Rejected)

            <div class="action-panel action-warning">
                <div>
                    <p class="eyebrow">Decision recorded</p>
                    <h2>Request rejected</h2>

                    <p>
                        Review the recorded SPMU remarks and request history below.
                    </p>
                </div>

                <a
                    class="button secondary ui-pressable"
                    href="#request-history"
                >
                    View decision history
                </a>
            </div>

            @break


        @case(App\Enums\RequestStatus::Cancelled)

            <div class="action-panel action-neutral">
                <div>
                    <p class="eyebrow">Request closed</p>
                    <h2>Request cancelled</h2>
                    <p>This request is no longer active.</p>
                </div>
            </div>

            @break


        @case(App\Enums\RequestStatus::Expired)

            <div class="action-panel action-neutral">
                <div>
                    <p class="eyebrow">Request record</p>
                    <h2>Request no longer active</h2>

                    <p>
                        Review the request history below for the final recorded status.
                    </p>
                </div>
            </div>

            @break


        @default

            <div class="action-panel action-neutral">
                <div>
                    <p class="eyebrow">Request record</p>
                    <h2>Review the latest request status</h2>

                    <p>
                        Review the request details, required documents, SPMU review,
                        and history below.
                    </p>
                </div>

                <x-status-badge
                    :status="$borrowerDisplayStatus"
                    :label="$borrowerDisplayStatusLabel"
                />
            </div>

    @endswitch

</section>


{{-- ========================================================= --}}
{{-- BORROWER WORKFLOW PROGRESS                                --}}
{{-- ========================================================= --}}

<section class="content-area">
    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">Request progress</p>
                <h2>Borrowing request workflow</h2>
            </div>
        </div>

        <ol class="approval-progress">

            <li>
                <span class="approval-marker" aria-hidden="true">1</span>

                <div>
                    <span class="approval-stage">Request details</span>

                    <x-status-badge
                        status="VERIFIED"
                        label="Saved"
                    />

                    <p>
                        Request details, borrowing schedule, premises,
                        and requested items are recorded.
                    </p>
                </div>
            </li>

            <li>
                <span class="approval-marker" aria-hidden="true">2</span>

                <div>
                    <span class="approval-stage">Print and sign BR Letter</span>

                    <x-status-badge
                        :status="$signedRequestLetter ? 'VERIFIED' : ($draftPreview ? 'PENDING' : 'PENDING')"
                        :label="$signedRequestLetter ? 'Signed copy uploaded' : ($draftPreview ? 'Ready to print' : 'Preview pending')"
                    />

                    <p>
                        Print the generated Borrowing Request Letter and obtain
                        the required handwritten signatures.
                    </p>
                </div>
            </li>

            <li>
                <span class="approval-marker" aria-hidden="true">3</span>

                <div>
                    <span class="approval-stage">Required documents</span>

                    <x-status-badge
                        :status="$hasRequiredSupportingDocuments ? 'VERIFIED' : 'PENDING'"
                        :label="$hasRequiredSupportingDocuments ? 'Complete' : 'Signed BR Letter + PTC required'"
                    />

                    <p>
                        Both the fully signed Borrowing Request Letter and
                        Permission to Conduct (PTC) Letter are required.
                    </p>
                </div>
            </li>

            <li>
                <span class="approval-marker" aria-hidden="true">4</span>

                <div>
                    <span class="approval-stage">SPMU review</span>

                    <x-status-badge
                        :status="$reviewProgressStatus"
                        :label="$reviewProgressLabel"
                    />

                    <p>
                        SPMU may approve, reject, or return the request for revision.
                        Inventory is reserved only when SPMU approves.
                    </p>
                </div>
            </li>

            <li>
                <span class="approval-marker" aria-hidden="true">5</span>

                <div>
                    <span class="approval-stage">Release / pickup</span>

                    <x-status-badge
                        :status="$custodyStatus ?: ($requestIsApproved ? 'VERIFIED' : 'PENDING')"
                        :label="$custodyStatus
                            ? $borrowerDisplayStatusLabel
                            : ($requestIsApproved ? 'Ready for Release' : 'After SPMU approval')"
                    />

                    <p>
                        After approval, SPMU prepares the release record and
                        coordinates physical pickup of the approved items.
                    </p>
                </div>
            </li>

        </ol>

    </article>
</section>



{{-- ========================================================= --}}
{{-- REQUEST SUMMARY                                           --}}
{{-- ========================================================= --}}

<section class="content-area">
    <article class="card request-summary-panel">

        <div class="card-header">
            <div>
                <p class="eyebrow">Request summary</p>
                <h2>Borrowing details</h2>
            </div>
        </div>

        <dl class="summary-grid">

            <div>
                <dt>Request number</dt>
                <dd>{{ $borrowingRequest->request_no }}</dd>
            </div>

            <div>
                <dt>Office/Department</dt>
                <dd>{{ $borrowingRequest->accountableUnit->unit_name }}</dd>
            </div>

            <div>
                <dt>Items needed from</dt>
                <dd>{{ $version->needed_from->format('d F Y') }}</dd>
            </div>

            <div>
                <dt>Expected return date</dt>
                <dd>{{ $version->return_due_at->format('d F Y') }}</dd>
            </div>

            <div>
                <dt>Location</dt>
                <dd>{{ $version->location }}</dd>
            </div>

            <div>
                <dt>Premises</dt>
                <dd>
                    {{
                        $version->off_campus
                            ? 'Off-campus'
                            : 'On-campus'
                    }}
                </dd>
            </div>

            @if($version->event_details)
                <div class="summary-wide">
                    <dt>Event or activity details</dt>
                    <dd>{{ $version->event_details }}</dd>
                </div>
            @endif

            @if($version->represents_student_activity)
                <div class="summary-wide">
                    <dt>Represented activity</dt>

                    <dd>
                        {{
                            collect([
                                $version->student_organization,
                                $version->represented_program_department,
                                $version->represented_year_level
                            ])->filter()->join(' · ') ?: 'Student activity'
                        }}
                    </dd>
                </div>
            @endif

        </dl>
    </article>
</section>


{{-- ========================================================= --}}
{{-- REQUESTED ITEMS                                           --}}
{{-- ========================================================= --}}

<section class="content-area">
    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">Requested items</p>
                <h2>Items included in this request</h2>
            </div>

            <span class="meta">
                {{ $version->items->count() }}
                {{ $version->items->count() === 1 ? 'item type' : 'item types' }}
            </span>
        </div>

        <div class="table-wrap borrower-detail-table">
            <table>

                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Description</th>
                        <th scope="col">Unit</th>
                        <th scope="col">Qty</th>
                        <th scope="col">Condition</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach($version->items as $item)
                        @php
                            $inventoryItem = $item->inventoryItem;

                            $itemName =
                                $inventoryItem?->unique_description
                                ?: $item->description_snapshot;

                            $itemDescription =
                                $inventoryItem?->specification
                                ?: '—';

                            $conditionCode =
                                $inventoryItem?->condition_code;

                            $conditionLabel = match($conditionCode) {
                                'SERVICEABLE' => 'Serviceable',
                                'DAMAGED_MAINTENANCE' => 'Damaged / Maintenance',
                                'CONDEMNED' => 'Condemned',
                                default => $conditionCode
                                    ? str($conditionCode)->replace('_', ' ')->lower()->title()
                                    : 'Not recorded',
                            };
                        @endphp

                        <tr>
                            <td data-label="Item">
                                <strong>{{ $itemName }}</strong>
                            </td>

                            <td data-label="Description">
                                {{ $itemDescription }}
                            </td>

                            <td data-label="Unit">
                                {{ $item->unit_snapshot }}
                            </td>

                            <td data-label="Qty">
                                {{ $item->requested_quantity + 0 }}
                            </td>

                            <td data-label="Condition">
                                <span class="borrower-readonly-condition">
                                    {{ $conditionLabel }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>

            </table>
        </div>

    </article>
</section>


{{-- ========================================================= --}}
{{-- REQUIRED DOCUMENTS                                        --}}
{{-- ========================================================= --}}

<section
    class="content-area"
    id="required-documents"
>
    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">Required before submission</p>
                <h2>Signed BR Letter and PTC</h2>
            </div>

            <x-status-badge
                :status="$hasRequiredSupportingDocuments ? 'VERIFIED' : 'PENDING'"
                :label="$hasRequiredSupportingDocuments ? 'Complete' : 'Incomplete'"
            />
        </div>

        <p class="meta">
            Print the generated Borrowing Request Letter and obtain the required
            handwritten signatures. Upload the fully signed copy together with
            the Permission to Conduct (PTC) Letter. Required signatures must be
            completed on the printed document.
        </p>

        <div class="borrower-request-docs top-gap">

            @foreach([
                'SIGNED_REQUEST_LETTER' => [
                    'Signed Borrowing Request Letter',
                    'Upload the fully signed copy of the generated Borrowing Request Letter.'
                ],
                'PTC' => [
                    'Permission to Conduct (PTC) Letter',
                    'Upload the supporting Permission to Conduct Letter for this request.'
                ],
            ] as $documentType => [$documentLabel, $documentHelp])

                @php
                    $supportingDocument =
                        $documentType === 'SIGNED_REQUEST_LETTER'
                            ? $signedRequestLetter
                            : $ptcDocument;
                @endphp

                <section
                    class="
                        borrower-document-slot
                        {{ $supportingDocument ? 'is-complete' : 'is-missing' }}
                    "
                >
                    <div class="borrower-document-slot-header">
                        <div>
                            <h3>{{ $documentLabel }}</h3>
                            <p>{{ $documentHelp }}</p>
                        </div>

                        <x-status-badge
                            :status="$supportingDocument ? 'VERIFIED' : 'PENDING'"
                            :label="$supportingDocument ? 'Uploaded' : 'Required'"
                        />
                    </div>

                    @if($supportingDocument)

                        <div>
                            <strong>
                                {{ $supportingDocument->file?->original_name ?: $documentLabel }}
                            </strong>

                            <small>
                                Uploaded
                                {{
                                    optional($supportingDocument->uploaded_at)
                                        ->format('d M Y, g:i A')
                                    ?: 'recently'
                                }}
                            </small>
                        </div>

                        <div class="actions">
                            @if($supportingDownloadRouteReady)
                                <a
                                    class="button secondary small ui-pressable"
                                    href="{{ route($supportingDownloadRouteName, $supportingDocument) }}"
                                >
                                    View uploaded file
                                </a>
                            @endif

                            @if(
                                $supportingUploadRouteReady
                                && in_array(
                                    $borrowingRequest->status,
                                    [
                                        App\Enums\RequestStatus::Draft,
                                        App\Enums\RequestStatus::ReturnedForRevision
                                    ],
                                    true
                                )
                            )
                                <span class="meta">
                                    Upload another file to replace this copy.
                                </span>
                            @endif
                        </div>

                    @endif


                    @if(in_array(
                        $borrowingRequest->status,
                        [
                            App\Enums\RequestStatus::Draft,
                            App\Enums\RequestStatus::ReturnedForRevision
                        ],
                        true
                    ))

                        @if($supportingUploadRouteReady)

                            <form
                                method="post"
                                action="{{ route('requests.supporting-documents.store', $borrowingRequest) }}"
                                enctype="multipart/form-data"
                            >
                                @csrf

                                <input
                                    type="hidden"
                                    name="document_type"
                                    value="{{ $documentType }}"
                                >

                                <label>
                                    {{ $supportingDocument ? 'Replace file' : 'Choose file' }}

                                    <input
                                        type="file"
                                        name="document"
                                        accept=".pdf,.png,.jpg,.jpeg,.webp"
                                        required
                                    >
                                </label>

                                <button
                                    type="submit"
                                    class="button secondary small ui-pressable"
                                >
                                    {{ $supportingDocument ? 'Replace upload' : 'Upload document' }}
                                </button>
                            </form>

                        @else

                            <div class="callout">
                                <strong>Upload not available yet</strong>

                                <p>
                                    This document will be uploaded here once the
                                    supporting-document upload function is enabled.
                                </p>
                            </div>

                        @endif

                    @endif

                </section>

            @endforeach

        </div>

        <div class="callout borrower-required-note">
            <strong>Important</strong>

            <p>
                Uploading these documents does not reserve inventory.
                Inventory is reserved only after SPMU approves the request.
            </p>
        </div>

    </article>
</section>


{{-- ========================================================= --}}
{{-- SPMU REVIEW + GENERATED DOCUMENTS                          --}}
{{-- ========================================================= --}}

<section class="content-grid request-progress-grid">

    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">Review progress</p>
                <h2>SPMU review</h2>
            </div>
        </div>

        <ol class="approval-progress">

            @forelse($spmuApprovalSteps as $step)

                <li>
                    <span
                        class="approval-marker"
                        aria-hidden="true"
                    >
                        1
                    </span>

                    <div>
                        <span class="approval-stage">
                            SPMU review
                        </span>

                        <x-status-badge
                            :status="$step->decision ?: 'PENDING'"
                        />

                        <p>
                            {{
                                $step->approver?->full_name
                                    ?: 'Awaiting authorized SPMU reviewer'
                            }}
                        </p>

                        <small>
                            @if($step->decided_at)
                                Decided {{ $step->decided_at->format('d M Y, g:i A') }}
                            @elseif($step->received_at)
                                Received {{ $step->received_at->format('d M Y, g:i A') }}
                            @else
                                Not yet received
                            @endif
                        </small>

                        @if($step->remarks)
                            <div class="review-remarks">
                                <strong>SPMU remarks</strong>
                                <p>{{ $step->remarks }}</p>
                            </div>
                        @endif
                    </div>
                </li>

            @empty

                <li class="approval-empty">
                    <span
                        class="approval-marker"
                        aria-hidden="true"
                    >
                        1
                    </span>

                    <div>
                        <strong>SPMU review begins after submission</strong>

                        <p>
                            Complete the signed Borrowing Request Letter and PTC,
                            then submit the request to SPMU.
                        </p>
                    </div>
                </li>

            @endforelse

        </ol>

    </article>


    <article
        class="card"
        id="request-documents"
    >

        <div class="card-header">
            <div>
                <p class="eyebrow">Generated document</p>
                <h2>Borrowing Request Letter</h2>
            </div>
        </div>

        <div class="document-list borrower-document-list">

            @forelse($borrowerGeneratedDocuments as $document)

                @php
                    $historical = in_array(
                        $document->status,
                        [
                            'SUPERSEDED',
                            'INVALIDATED',
                            'EXPIRED'
                        ],
                        true
                    );
                @endphp

                <article>
                    <div>
                        <strong>
                            {{
                                $documentNames[$document->document_type]
                                    ?? str($document->document_type)
                                        ->replace('_', ' ')
                                        ->lower()
                                        ->title()
                            }}
                        </strong>

                        <small>
                            {{ $document->document_no }}
                            · Generated
                            {{ $document->generated_at->format('d M Y, g:i A') }}
                        </small>

                        <x-status-badge
                            :status="$document->status"
                            :label="$historical ? 'Historical record' : null"
                        />
                    </div>

                    @if(!$historical)
                        <a
                            class="button secondary small ui-pressable"
                            href="{{ route('documents.download', $document) }}"
                        >
                            Download
                        </a>
                    @endif
                </article>

            @empty

                <div class="empty-state">
                    <strong>No generated document available yet.</strong>

                    <span>
                        The Borrowing Request Letter will appear after the draft is saved.
                    </span>
                </div>

            @endforelse

        </div>


        @if(
            $borrowingRequest->status === App\Enums\RequestStatus::Draft
            && !$hasActiveDraftPreview
        )

            <div class="callout warning">
                <strong>Borrowing Request Letter preview missing</strong>

                <p>
                    Your saved request and item lines are intact.
                    Regenerate only the missing printable preview.
                </p>

                <form
                    method="post"
                    action="{{ route('requests.recover-draft-document', $borrowingRequest) }}"
                >
                    @csrf

                    <button class="button secondary">
                        Regenerate preview
                    </button>
                </form>
            </div>

        @endif

    </article>

</section>


{{-- ========================================================= --}}
{{-- SUBMIT TO SPMU                                            --}}
{{-- ========================================================= --}}

@if($borrowingRequest->status === App\Enums\RequestStatus::Draft)

    <section class="content-area narrow">

        <form
            method="post"
            action="{{ route('requests.submit', $borrowingRequest) }}"
            class="card certification-panel"
        >
            @csrf

            <p class="eyebrow">Submit request</p>
            <h2>Submit to SPMU</h2>

            <p>
                Submit only after the fully signed Borrowing Request Letter and
                PTC have been uploaded. Submission sends the request to SPMU for
                review and does not reserve inventory.
            </p>

            <ul class="borrower-submit-checks">
                <li>
                    <span class="borrower-check-mark {{ $signedRequestLetter ? 'is-complete' : 'is-missing' }}">
                        {{ $signedRequestLetter ? '✓' : '!' }}
                    </span>

                    <span>
                        <strong>Signed BR Letter:</strong>
                        {{ $signedRequestLetter ? 'Uploaded' : 'Required' }}
                    </span>
                </li>

                <li>
                    <span class="borrower-check-mark {{ $ptcDocument ? 'is-complete' : 'is-missing' }}">
                        {{ $ptcDocument ? '✓' : '!' }}
                    </span>

                    <span>
                        <strong>PTC Letter:</strong>
                        {{ $ptcDocument ? 'Uploaded' : 'Required' }}
                    </span>
                </li>
            </ul>

            <div class="actions top-gap">
                <button
                    class="button primary ui-pressable"
                    @disabled(!$hasRequiredSupportingDocuments)
                >
                    Submit to SPMU
                </button>

                <a
                    class="button secondary"
                    href="{{ route('requests.edit', $borrowingRequest) }}"
                >
                    Edit request
                </a>
            </div>

            @if(!$hasRequiredSupportingDocuments)
                <p class="field-help">
                    Upload both required documents before submission.
                </p>
            @endif

        </form>

    </section>

@endif


{{-- ========================================================= --}}
{{-- CUSTODY LINK                                              --}}
{{-- ========================================================= --}}

@if($custody)

    <section class="content-area">

        <div class="action-panel action-success">

            <div>
                <p class="eyebrow">Release and custody</p>

                @if($custodyStatus === 'ACTIVE')

                    <h2>Items currently under custody</h2>

                    <p>
                        Review actual released quantities, return deadlines,
                        and current item status.
                    </p>

                @elseif($custodyStatus === 'CLOSED')

                    <h2>Completed borrowing record</h2>

                    <p>
                        Review the completed release and return history.
                    </p>

                @else

                    <h2>Release record available</h2>

                    <p>
                        Review the latest release preparation, issued quantities,
                        and return status.
                    </p>

                @endif
            </div>

            <a
                class="button primary ui-pressable"
                href="{{ route('custody.show', $custody) }}"
            >
                Open my borrowing
            </a>

        </div>

    </section>

@endif


{{-- ========================================================= --}}
{{-- CANCEL REQUEST                                            --}}
{{-- ========================================================= --}}

@if(
    !in_array(
        $borrowingRequest->status,
        [
            App\Enums\RequestStatus::Cancelled,
            App\Enums\RequestStatus::Rejected,
            App\Enums\RequestStatus::Expired
        ],
        true
    )
    && !$custody?->released_at
)

    <section class="content-area narrow">

        <details class="card danger-zone compact-danger">

            <summary>Cancel this request</summary>

            <form
                method="post"
                action="{{ route('requests.cancel', $borrowingRequest) }}"
                class="form-grid top-gap"
            >
                @csrf

                <label>
                    Reason for cancellation

                    <textarea
                        name="reason"
                        required
                    ></textarea>
                </label>

                <p class="meta">
                    Cancellation is recorded in the request history.
                    Any unreleased reservation, if one exists, is restored.
                </p>

                <button class="button danger">
                    Cancel request
                </button>
            </form>

        </details>

    </section>

@endif


{{-- ========================================================= --}}
{{-- BORROWER REQUEST HISTORY                                  --}}
{{-- ========================================================= --}}

<section
    class="content-area"
    id="request-history"
>

    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">History</p>
                <h2>Request activity</h2>
            </div>
        </div>

        <div class="timeline borrower-history">

            @forelse($borrowingRequest->statusHistory as $history)

                @php
                    $historyStatusValue =
                        $history->to_status instanceof \BackedEnum
                            ? $history->to_status->value
                            : (string) $history->to_status;

                    $historyDisplayStatus = match($historyStatusValue) {
                        'UNDER_GSU',
                        'UNDER_VPAF' => 'UNDER_SPMU',

                        'FINAL_APPROVED_AWAITING_DOWNLOAD'
                            => 'APPROVED_READY_FOR_RELEASE',

                        default => $history->to_status,
                    };

                    $historyDisplayLabel = match($historyStatusValue) {
                        'UNDER_GSU',
                        'UNDER_VPAF' => 'Under Review',

                        'UNDER_SPMU' => 'Under SPMU Review',

                        'FINAL_APPROVED_AWAITING_DOWNLOAD'
                            => 'Approved',

                        default => null,
                    };

                    $historyReason = match($historyStatusValue) {
                        'UNDER_GSU',
                        'UNDER_VPAF'
                            => 'Request review status updated.',

                        'FINAL_APPROVED_AWAITING_DOWNLOAD'
                            => 'Request approved and prepared for release processing.',

                        default => $history->reason ?: 'Status updated.',
                    };
                @endphp

                <article>

                    <span>
                        {{ $history->changed_at->format('d M') }}
                    </span>

                    <div>

                        <x-status-badge
                            :status="$historyDisplayStatus"
                            :label="$historyDisplayLabel"
                        />

                        <p>{{ $historyReason }}</p>

                        <small>
                            {{ $history->actor?->full_name ?: 'System' }}
                            ·
                            {{ $history->changed_at->format('d M Y, g:i A') }}
                        </small>

                    </div>

                </article>

            @empty

                <div class="empty-state">
                    No status changes have been recorded yet.
                </div>

            @endforelse

        </div>

    </article>

</section>


@else

{{-- ========================================================= --}}
{{-- SPMU / GSU / VPAF REVIEW VIEW                             --}}
{{-- ========================================================= --}}

@php
    $selectedDecision = old('decision', '');

    $remarksRequired = in_array(
        $selectedDecision,
        [
            'RETURNED_FOR_REVISION',
            'REJECTED'
        ],
        true
    );

    $decisionTone = match($selectedDecision) {
        'APPROVED' => 'approve',
        'RETURNED_FOR_REVISION' => 'return',
        'REJECTED' => 'reject',
        default => 'neutral',
    };

    $approvalOutcome = match($approvalStage) {
        'SPMU' =>
            'Approval routes this request to GSU review. Inventory is not allocated at this stage.',

        'GSU' =>
            'Approval routes this request to VPAF review. Inventory is not allocated at this stage.',

        'VPAF' =>
            'Final approval performs the existing availability check and allocation transaction.',

        default => null,
    };
@endphp


<section class="page-heading approval-review-heading">

    <div>

        <p class="eyebrow">
            {{ $borrowingRequest->request_no }}
            · Version {{ $version->version_no }}
        </p>

        <h1>
            {{
                $version->purpose_event
                    ?: $borrowingRequest->request_no
            }}
        </h1>

        <p class="heading-status">

            <x-status-badge
                :status="$displayStatus"
                :label="$displayStatusLabel"
            />

            <span>
                Borrower:
                {{ $borrowingRequest->borrower->full_name }}
            </span>

        </p>

    </div>

    <a
        class="button ghost ui-pressable"
        href="{{ route('approvals.index') }}"
    >
        Back to Approval Queue
    </a>

</section>


{{-- ========================================================= --}}
{{-- APPROVER REQUEST SUMMARY                                  --}}
{{-- ========================================================= --}}

<section class="content-area">

    <article class="card approval-review-summary">

        <div class="card-header">
            <div>
                <p class="eyebrow">Request information</p>
                <h2>Request summary</h2>
            </div>
        </div>

        <dl class="summary-grid">

            <div>
                <dt>Request number</dt>
                <dd>{{ $borrowingRequest->request_no }}</dd>
            </div>

            <div>
                <dt>Borrower</dt>

                <dd>
                    {{ $borrowingRequest->borrower->full_name }}

                    <small>
                        {{ $borrowingRequest->borrower->employee_no }}
                    </small>
                </dd>
            </div>

            <div>
                <dt>Office/Department</dt>
                <dd>{{ $borrowingRequest->accountableUnit->unit_name }}</dd>
            </div>

            <div>
                <dt>Submitted</dt>
                <dd>
                    <x-date
                        :value="$version->submitted_at"
                        with-time
                        fallback="Not submitted"
                    />
                </dd>
            </div>

            @if($version->event_details)

                <div class="summary-wide">
                    <dt>Event or activity details</dt>
                    <dd>{{ $version->event_details }}</dd>
                </div>

            @endif

            @if($version->represents_student_activity)

                <div class="summary-wide">

                    <dt>Represented activity</dt>

                    <dd>
                        {{
                            collect([
                                $version->student_organization,
                                $version->represented_program_department,
                                $version->represented_year_level
                            ])->filter()->join(' · ')
                                ?: 'Student activity'
                        }}
                    </dd>

                </div>

            @endif

        </dl>

    </article>

</section>


{{-- ========================================================= --}}
{{-- APPROVAL CONTEXT                                          --}}
{{-- ========================================================= --}}

<section class="content-grid two approval-review-context">

    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">Borrowing schedule</p>
                <h2>Use period and location</h2>
            </div>
        </div>

        <dl class="approval-schedule-grid">

            <div>
                <dt>Items needed from</dt>

                <dd>
                    <x-date
                        :value="$version->needed_from"
                        with-time
                    />
                </dd>
            </div>

            <div>
                <dt>Expected return</dt>

                <dd>
                    <x-date
                        :value="$version->return_due_at"
                        with-time
                    />
                </dd>
            </div>

            <div class="approval-schedule-wide">
                <dt>Location</dt>
                <dd>{{ $version->location }}</dd>
            </div>

        </dl>


        @if($version->off_campus)

            <div class="approval-off-campus-note">

                <span class="context-chip context-chip-warning">
                    Off-campus
                </span>

                <p>
                    This request includes off-campus property use.
                    Existing Gate Pass controls remain a later release requirement.
                </p>

            </div>

        @endif

    </article>


    <article class="card approval-certification-panel">

        <div class="card-header">
            <div>
                <p class="eyebrow">Certification</p>
                <h2>Borrower certification</h2>
            </div>
        </div>

        <dl class="detail-list compact">

            <dt>Accuracy certification</dt>

            <dd>
                <x-status-badge
                    :status="$version->accuracy_certified ? 'VERIFIED' : 'PENDING'"
                    :label="$version->accuracy_certified ? 'Certified' : 'Pending'"
                />
            </dd>

            <dt>E-signature snapshot</dt>

            <dd>
                <x-status-badge
                    :status="$version->borrower_signature_snapshot_id ? 'VERIFIED' : 'PENDING'"
                    :label="$version->borrower_signature_snapshot_id ? 'Recorded' : 'Pending'"
                />
            </dd>

            <dt>Signed</dt>

            <dd>
                <x-date
                    :value="$version->signed_at"
                    with-time
                    fallback="Not signed"
                />
            </dd>

        </dl>

    </article>

</section>


{{-- ========================================================= --}}
{{-- APPROVER ITEM REVIEW                                      --}}
{{-- ========================================================= --}}

<section class="content-area">

    <article class="card approval-items-panel">

        <div class="card-header">

            <div>
                <p class="eyebrow">Requested items</p>
                <h2>Property and quantities</h2>
            </div>

            <span class="meta">
                {{ $version->items->count() }} item type(s)
            </span>

        </div>

        <div class="table-wrap approval-items-table">

            <table>

                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Use location</th>
                        <th scope="col">Requested quantity</th>
                        <th scope="col">Approved quantity</th>
                    </tr>
                </thead>

                <tbody>

                    @foreach($version->items as $item)

                        <tr>

                            <td>
                                <strong>
                                    {{ $item->description_snapshot }}
                                </strong>
                            </td>

                            <td>
                                {{
                                    str($item->use_location)
                                        ->replace('_', ' ')
                                        ->lower()
                                        ->title()
                                }}
                            </td>

                            <td>
                                {{ $item->requested_quantity + 0 }}
                                {{ $item->unit_snapshot }}
                            </td>

                            <td>
                                {{
                                    $item->approved_quantity !== null
                                        ? ($item->approved_quantity + 0).' '.$item->unit_snapshot
                                        : 'Pending review'
                                }}
                            </td>

                        </tr>

                    @endforeach

                </tbody>

            </table>

        </div>

    </article>

</section>


{{-- ========================================================= --}}
{{-- APPROVAL SUPPORTING INFO                                  --}}
{{-- ========================================================= --}}

<section class="content-grid two approval-review-supporting">

    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">Approval progress</p>
                <h2>SPMU → GSU → VPAF</h2>
            </div>
        </div>

        <ol class="approval-progress">

            @foreach(
                $version->approvalSteps->sortBy('sequence_no')
                as $step
            )

                <li>

                    <span
                        class="approval-marker"
                        aria-hidden="true"
                    >
                        {{ $step->sequence_no }}
                    </span>

                    <div>

                        <span class="approval-stage">
                            {{ $step->stage_code->value }} review
                        </span>

                        <x-status-badge
                            :status="$step->decision ?: 'PENDING'"
                        />

                        <p>
                            {{
                                $step->approver?->full_name
                                    ?: 'Awaiting authorized reviewer'
                            }}
                        </p>

                        <small>

                            @if($step->decided_at)

                                Decided
                                <x-date
                                    :value="$step->decided_at"
                                    with-time
                                />

                            @elseif($step->received_at)

                                Received
                                <x-date
                                    :value="$step->received_at"
                                    with-time
                                />

                            @else

                                Not yet reached

                            @endif

                        </small>


                        @if($step->remarks)

                            <div class="review-remarks">
                                <strong>Reviewer remarks</strong>
                                <p>{{ $step->remarks }}</p>
                            </div>

                        @endif

                    </div>

                </li>

            @endforeach

        </ol>

    </article>


    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">Documents</p>
                <h2>Controlled documents</h2>
            </div>
        </div>

        <div class="document-list approval-document-list">

            @forelse(
                $version->documents->sortByDesc('generated_at')
                as $document
            )

                @php
                    $historical = in_array(
                        $document->status,
                        [
                            'SUPERSEDED',
                            'INVALIDATED',
                            'EXPIRED'
                        ],
                        true
                    );
                @endphp

                <article>

                    <div>

                        <strong>
                            {{
                                $documentNames[$document->document_type]
                                    ?? str($document->document_type)
                                        ->replace('_', ' ')
                                        ->lower()
                                        ->title()
                            }}
                        </strong>

                        <small>
                            {{ $document->document_no }}
                            · Generated
                            <x-date
                                :value="$document->generated_at"
                                with-time
                            />
                        </small>

                        <x-status-badge
                            :status="$document->status"
                            :label="$historical ? 'Historical record' : null"
                        />

                    </div>

                    @if(!$historical)

                        <a
                            class="button secondary small ui-pressable"
                            href="{{ route('documents.download', $document) }}"
                        >
                            Download
                        </a>

                    @endif

                </article>

            @empty

                <div class="empty-state">
                    Documents will appear as the request progresses.
                </div>

            @endforelse

        </div>

    </article>

</section>


{{-- ========================================================= --}}
{{-- CURRENT APPROVAL DECISION                                 --}}
{{-- ========================================================= --}}

@if($approvalStage)

    <section
        class="content-area approval-decision-section"
        id="current-decision"
    >

        <article
            class="card approval-decision-panel"
            data-approval-decision-panel
            data-decision-tone="{{ $decisionTone }}"
        >

            <div class="card-header">

                <div>
                    <p class="eyebrow">Current decision</p>
                    <h2>{{ $approvalStage }} decision</h2>
                </div>

                <x-status-badge :status="$borrowingRequest->status" />

            </div>


            @if($canDecide)

                <form
                    method="post"
                    action="{{ route('approvals.decide', $borrowingRequest) }}"
                    class="form-grid approval-decision-form"
                    data-approval-decision-form
                >

                    @csrf

                    <label for="approval-decision">

                        Decision

                        <select
                            id="approval-decision"
                            name="decision"
                            required
                            data-approval-decision

                            @error('decision')
                                aria-invalid="true"
                                aria-describedby="approval-decision-help approval-decision-error"
                            @else
                                aria-describedby="approval-decision-help"
                            @enderror
                        >

                            <option
                                value=""
                                disabled
                                @selected($selectedDecision === '')
                            >
                                Select a decision
                            </option>

                            <option
                                value="APPROVED"
                                @selected($selectedDecision === 'APPROVED')
                            >
                                Approve
                            </option>

                            <option
                                value="RETURNED_FOR_REVISION"
                                @selected($selectedDecision === 'RETURNED_FOR_REVISION')
                            >
                                Return for Revision
                            </option>

                            <option
                                value="REJECTED"
                                @selected($selectedDecision === 'REJECTED')
                            >
                                Reject
                            </option>

                        </select>

                    </label>

                    <p
                        class="field-help"
                        id="approval-decision-help"
                    >
                        {{ $approvalOutcome }}
                    </p>

                    @error('decision')

                        <p
                            class="field-error"
                            id="approval-decision-error"
                            role="alert"
                        >
                            {{ $message }}
                        </p>

                    @enderror


                    <label for="approval-remarks">

                        <span data-approval-remarks-label>
                            {{
                                $remarksRequired
                                    ? (
                                        $selectedDecision === 'REJECTED'
                                            ? 'Reason for rejection (required)'
                                            : 'Reason for return (required)'
                                    )
                                    : 'Remarks (optional)'
                            }}
                        </span>

                        <textarea
                            id="approval-remarks"
                            name="remarks"
                            maxlength="2000"
                            placeholder="Enter remarks or reason..."
                            data-approval-remarks
                            @required($remarksRequired)

                            @error('remarks')
                                aria-invalid="true"
                                aria-describedby="approval-remarks-help approval-remarks-error"
                            @else
                                aria-describedby="approval-remarks-help"
                            @enderror
                        >{{ old('remarks') }}</textarea>

                    </label>

                    <p
                        class="field-help"
                        id="approval-remarks-help"
                        data-approval-remarks-help
                    >
                        {{
                            $remarksRequired
                                ? 'A reason is required for this decision.'
                                : (
                                    $selectedDecision === 'APPROVED'
                                        ? 'Remarks are optional when approving.'
                                        : 'A reason is required when returning or rejecting a request.'
                                )
                        }}
                    </p>

                    @error('remarks')

                        <p
                            class="field-error"
                            id="approval-remarks-error"
                            role="alert"
                        >
                            {{ $message }}
                        </p>

                    @enderror


                    <div class="approval-decision-footer">

                        <p>
                            This decision will be recorded in the request
                            history with your immutable e-signature snapshot.
                        </p>

                        <button class="button primary ui-pressable">
                            Submit decision
                        </button>

                    </div>

                </form>

            @else

                <div class="callout">

                    <strong>Review-only access</strong>

                    <p>
                        Only the office Head or a currently authorized
                        temporary delegate may submit this approval.
                        Self-approval remains prohibited.
                    </p>

                </div>

            @endif

        </article>

    </section>

@endif


{{-- ========================================================= --}}
{{-- SPMU CUSTODY LINK                                         --}}
{{-- ========================================================= --}}

@if(
    $custody
    && auth()->user()->hasRole('SPMU')
)

    <section class="content-area narrow">

        <a
            class="button primary ui-pressable"
            href="{{ route('custody.show', $custody) }}"
        >
            Open Borrower's Slip and custody
        </a>

    </section>

@endif


{{-- ========================================================= --}}
{{-- SPMU REQUEST CANCELLATION                                 --}}
{{-- ========================================================= --}}

@if(
    !in_array(
        $borrowingRequest->status,
        [
            App\Enums\RequestStatus::Cancelled,
            App\Enums\RequestStatus::Rejected,
            App\Enums\RequestStatus::Expired
        ],
        true
    )
    && !$custody?->released_at
    && auth()->user()->hasRole('SPMU')
)

    <section class="content-area narrow">

        <form
            method="post"
            action="{{ route('requests.cancel', $borrowingRequest) }}"
            class="card danger-zone"
        >

            @csrf

            <h2>Cancel request</h2>

            <label>
                Mandatory reason

                <textarea
                    name="reason"
                    required
                ></textarea>
            </label>

            <button class="button danger">
                Cancel and restore unreleased allocation
            </button>

        </form>

    </section>

@endif


{{-- ========================================================= --}}
{{-- APPROVER REQUEST HISTORY                                  --}}
{{-- ========================================================= --}}

<section class="content-area">

    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">History</p>
                <h2>Request activity</h2>
            </div>
        </div>

        <div class="timeline">

            @forelse($borrowingRequest->statusHistory as $history)

                <article>

                    <span>
                        {{ $history->changed_at->format('d M') }}
                    </span>

                    <div>

                        <x-status-badge :status="$history->to_status" />

                        <p>
                            {{ $history->reason ?: 'Status updated.' }}
                        </p>

                        <small>
                            {{ $history->actor?->full_name ?: 'System' }}
                            ·
                            <x-date
                                :value="$history->changed_at"
                                with-time
                            />
                        </small>

                    </div>

                </article>

            @empty

                <div class="empty-state">
                    No status changes have been recorded yet.
                </div>

            @endforelse

        </div>

    </article>

</section>

@endif

@endsection
