@extends('layouts.app', ['title' => $borrowingRequest->request_no])

@section('content')

@php
    $version = $borrowingRequest->currentVersion;

    /*
    |--------------------------------------------------------------------------
    | Effective display status
    |--------------------------------------------------------------------------
    |
    | The borrowing request keeps APPROVED_READY_FOR_RELEASE as its request
    | workflow status even after physical release. Once custody exists, the
    | custody status is the more accurate operational status to show to users.
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

    $approvedLetter = $version->documents->first(
        fn($document) =>
            $document->document_type === 'APPROVED_REQUEST_LETTER'
            && $document->status === 'FINAL'
    );

    $documentNames = [
        'REQUEST_LETTER' => 'Borrowing Request Letter',
        'APPROVED_REQUEST_LETTER' => 'Approved Request Letter',
        'BORROWER_SLIP' => "Borrower’s Slip",
        'GATE_PASS' => 'Gate Pass',
        'LAUNDRY_FORM' => 'Laundry Form',
        'BILLING_STATEMENT' => 'Billing Statement',
    ];
@endphp


@if($isBorrower)

{{-- ========================================================= --}}
{{-- BORROWER VIEW                                             --}}
{{-- ========================================================= --}}

<section class="page-heading request-detail-heading">
    <div>
        <p class="eyebrow">
            Request {{ $borrowingRequest->request_no }}
            · Version {{ $version->version_no }}
        </p>

        <h1>{{ $version->purpose_event }}</h1>

        <p class="heading-status">
            <x-status-badge
                :status="$displayStatus"
                :label="$displayStatusLabel"
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
            Back to requests
        </a>
    </div>
</section>


{{-- ========================================================= --}}
{{-- CURRENT REQUEST / CUSTODY ACTION                          --}}
{{-- ========================================================= --}}

<section class="content-area">

    @switch($borrowingRequest->status)

        @case(App\Enums\RequestStatus::Draft)

            <div class="action-panel action-primary">
                <div>
                    <p class="eyebrow">Action required</p>

                    <h2>Review and submit your request</h2>

                    <p>
                        Confirm the request details and official preview,
                        then certify and e-sign the saved draft below.
                    </p>
                </div>

                <div class="actions">
                    @if($draftPreview)
                        <a
                            class="button secondary ui-pressable"
                            href="{{ route('documents.download', $draftPreview) }}"
                        >
                            Download draft preview
                        </a>
                    @endif

                    <a
                        class="button primary ui-pressable"
                        href="#certify-request"
                    >
                        Review certification
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
                        Review the approval remarks, correct the request,
                        and save the next version before submitting again.
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

            <div class="action-panel action-warning">
                <div>
                    <p class="eyebrow">Action required</p>

                    <h2>Download the approved request letter</h2>

                    <p>
                        Download the final approved letter by
                        {{ optional($borrowingRequest->download_deadline_at)->format('d F Y, g:i A') }}.
                        This unlocks the Borrower’s Slip and release processing.
                    </p>
                </div>

                @if($approvedLetter)
                    <a
                        class="button primary ui-pressable"
                        href="{{ route('documents.download', $approvedLetter) }}"
                    >
                        Download approved letter
                    </a>
                @endif
            </div>

            @break


        @case(App\Enums\RequestStatus::ApprovedReadyForRelease)

            @switch($custodyStatus)

                {{-- ----------------------------------------- --}}
                {{-- PHYSICALLY RELEASED                       --}}
                {{-- ----------------------------------------- --}}

                @case('ACTIVE')

                    <div class="action-panel action-success">
                        <div>
                            <p class="eyebrow">Physical release completed</p>

                            <h2>Items released</h2>

                            <p>
                                Your approved items have been physically released
                                by SPMU and are now under your custody.
                                Review the borrowing record for actual issued
                                quantities and the return deadline.
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


                {{-- ----------------------------------------- --}}
                {{-- PARTIALLY RETURNED                        --}}
                {{-- ----------------------------------------- --}}

                @case('PARTIALLY_RETURNED')

                    <div class="action-panel action-warning">
                        <div>
                            <p class="eyebrow">Return in progress</p>

                            <h2>Partially returned</h2>

                            <p>
                                Some released quantities have already been returned.
                                Review the borrowing record to see which quantities
                                are still under your custody.
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


                {{-- ----------------------------------------- --}}
                {{-- OVERDUE                                   --}}
                {{-- ----------------------------------------- --}}

                @case('OVERDUE')

                    <div class="action-panel action-warning">
                        <div>
                            <p class="eyebrow">Return required</p>

                            <h2>Borrowing overdue</h2>

                            <p>
                                The return deadline has passed.
                                Review your borrowing record and coordinate the
                                physical return with SPMU.
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


                {{-- ----------------------------------------- --}}
                {{-- EARLY RETURN                              --}}
                {{-- ----------------------------------------- --}}

                @case('EARLY_RETURN')

                    <div class="action-panel action-primary">
                        <div>
                            <p class="eyebrow">Return coordination</p>

                            <h2>Early Return in progress</h2>

                            <p>
                                An Early Return process is recorded for this
                                borrowing. Inventory quantities will change only
                                after SPMU physically receives and inspects the items.
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


                {{-- ----------------------------------------- --}}
                {{-- INCIDENT OPEN                             --}}
                {{-- ----------------------------------------- --}}

                @case('INCIDENT_OPEN')

                    <div class="action-panel action-warning">
                        <div>
                            <p class="eyebrow">Accountability review</p>

                            <h2>Incident remains open</h2>

                            <p>
                                An incident or accountability issue remains open
                                for this borrowing. Review the custody record for
                                the latest condition and required action.
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


                {{-- ----------------------------------------- --}}
                {{-- ITEMS RETURNED, OBLIGATION STILL OPEN     --}}
                {{-- ----------------------------------------- --}}

                @case('OBLIGATION_OPEN')

                    <div class="action-panel action-warning">
                        <div>
                            <p class="eyebrow">Items returned</p>

                            <h2>Outstanding obligation remains</h2>

                            <p>
                                The physical items have been returned, but an
                                outstanding obligation still requires completion
                                before the custody record can be fully closed.
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


                {{-- ----------------------------------------- --}}
                {{-- FULLY COMPLETED                           --}}
                {{-- ----------------------------------------- --}}

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


                {{-- ----------------------------------------- --}}
                {{-- STILL PREPARING FOR RELEASE               --}}
                {{-- ----------------------------------------- --}}

                @default

                    <div class="action-panel action-success">
                        <div>
                            <p class="eyebrow">Approved request</p>

                            <h2>Ready for release processing</h2>

                            <p>
                                {{
                                    $custody
                                        ? 'Open your Borrower’s Slip to review preparation, acknowledgement, and release details.'
                                        : 'SPMU is preparing the Borrower’s Slip and release record. No action is required yet.'
                                }}
                            </p>
                        </div>

                        @if($custody)
                            <a
                                class="button primary ui-pressable"
                                href="{{ route('custody.show', $custody) }}"
                            >
                                Open Borrower’s Slip
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
                        The approval progress below shows the current stage.
                        You will be notified if a revision or another action
                        is required.
                    </p>
                </div>

                <x-status-badge :status="$borrowingRequest->status" />
            </div>

            @break


        @default

            <div class="action-panel action-neutral">
                <div>
                    <p class="eyebrow">Request record</p>

                    <h2>No action is currently available</h2>

                    <p>
                        Review the status, approval remarks,
                        documents, and history below.
                    </p>
                </div>

                <x-status-badge
                    :status="$displayStatus"
                    :label="$displayStatusLabel"
                />
            </div>

    @endswitch

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
                <dd>{{ $version->needed_from->format('d F Y, g:i A') }}</dd>
            </div>

            <div>
                <dt>Expected return</dt>
                <dd>{{ $version->return_due_at->format('d F Y, g:i A') }}</dd>
            </div>

            <div>
                <dt>Location</dt>
                <dd>{{ $version->location }}</dd>
            </div>

            <div>
                <dt>Campus use</dt>
                <dd>
                    {{
                        $version->off_campus
                            ? 'Includes approved off-campus item use'
                            : 'On-campus use only'
                    }}
                </dd>
            </div>

            <div class="summary-wide">
                <dt>Event or activity details</dt>
                <dd>{{ $version->event_details }}</dd>
            </div>

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
                <h2>Property and quantities</h2>
            </div>

            <span class="meta">
                {{ $version->items->count() }} item type(s)
            </span>
        </div>

        <div class="table-wrap borrower-detail-table">
            <table>

                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Use location</th>
                        <th scope="col">Requested</th>
                        <th scope="col">Approved</th>
                    </tr>
                </thead>

                <tbody>

                    @foreach($version->items as $item)
                        <tr>
                            <td data-label="Item">
                                <strong>{{ $item->description_snapshot }}</strong>
                                <small>{{ $item->unit_snapshot }}</small>
                            </td>

                            <td data-label="Use location">
                                {{
                                    str($item->use_location)
                                        ->replace('_', ' ')
                                        ->lower()
                                        ->title()
                                }}
                            </td>

                            <td data-label="Requested">
                                {{ $item->requested_quantity + 0 }}
                                {{ $item->unit_snapshot }}
                            </td>

                            <td data-label="Approved">
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
{{-- APPROVAL PROGRESS + DOCUMENTS                             --}}
{{-- ========================================================= --}}

<section class="content-grid request-progress-grid">

    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">Approval progress</p>
                <h2>SPMU → GSU → VPAF</h2>
            </div>
        </div>

        <ol class="approval-progress">

            @forelse($version->approvalSteps->sortBy('sequence_no') as $step)

                @php
                    $decision = $step->decision ?: 'PENDING';
                @endphp

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

                        <x-status-badge :status="$decision" />

                        <p>
                            {{
                                $step->approver?->full_name
                                    ?: 'Awaiting authorized reviewer'
                            }}
                        </p>

                        <small>

                            @if($step->decided_at)

                                Decided
                                {{ $step->decided_at->format('d M Y, g:i A') }}

                            @elseif($step->received_at)

                                Received
                                {{ $step->received_at->format('d M Y, g:i A') }}

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

            @empty

                <li class="approval-empty">

                    <span
                        class="approval-marker"
                        aria-hidden="true"
                    >
                        1
                    </span>

                    <div>
                        <strong>Approval begins after submission</strong>

                        <p>
                            Save, review, certify, and submit the draft
                            to start SPMU review.
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
                <p class="eyebrow">Documents</p>
                <h2>Controlled documents</h2>
            </div>
        </div>

        <div class="document-list borrower-document-list">

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
                    <strong>No documents available yet.</strong>

                    <span>
                        Generated request and approval documents
                        will appear here.
                    </span>
                </div>

            @endforelse

        </div>


        @if(
            $borrowingRequest->status === App\Enums\RequestStatus::Draft
            && !$hasActiveDraftPreview
        )

            <div class="callout warning">

                <strong>Draft preview missing</strong>

                <p>
                    Your saved request and item lines are intact.
                    Regenerate only the missing request-letter preview.
                </p>

                <form
                    method="post"
                    action="{{ route('requests.recover-draft-document', $borrowingRequest) }}"
                >
                    @csrf

                    <button class="button secondary">
                        Regenerate missing preview
                    </button>
                </form>

            </div>

        @endif

    </article>

</section>


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
                        Review actual released quantities,
                        return deadlines, and current item status.
                    </p>

                @elseif($custodyStatus === 'CLOSED')

                    <h2>Completed borrowing record</h2>

                    <p>
                        Review the completed release and return history.
                    </p>

                @else

                    <h2>Borrower’s Slip available</h2>

                    <p>
                        Review actual issued quantities,
                        required documents, acknowledgement,
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
{{-- CERTIFY DRAFT                                             --}}
{{-- ========================================================= --}}

@if($borrowingRequest->status === App\Enums\RequestStatus::Draft)

    <section
        class="content-area narrow"
        id="certify-request"
    >

        <form
            method="post"
            action="{{ route('requests.submit', $borrowingRequest) }}"
            class="card certification-panel"
        >

            @csrf

            <p class="eyebrow">Official certification</p>

            <h2>Certify, e-sign, and submit</h2>

            <p>
                I certify that the request data and item quantities are accurate.
                Submitting records an immutable snapshot of my current profile
                e-signature and sends the request to SPMU for review.
            </p>

            <div class="actions">

                <button class="button primary ui-pressable">
                    Sign and submit to SPMU
                </button>

                <a
                    class="button secondary"
                    href="{{ route('requests.edit', $borrowingRequest) }}"
                >
                    Edit before submitting
                </a>

            </div>

        </form>

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
                    Cancellation is recorded in the request history
                    and restores any unreleased allocation.
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

<section class="content-area">

    <article class="card">

        <div class="card-header">
            <div>
                <p class="eyebrow">History</p>
                <h2>Request activity</h2>
            </div>
        </div>

        <div class="timeline borrower-history">

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