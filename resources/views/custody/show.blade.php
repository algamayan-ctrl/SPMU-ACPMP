@extends('layouts.app', ['title' => $custody->custody_no])

@section('content')
@php
    $workspace = strtoupper((string) session('active_workspace'));
    $user = auth()->user();
    $isBorrower = $workspace === 'BORROWER' && auth()->id() === $custody->borrower_user_id;
    $isSpmu = $workspace === 'SPMU';
    $isSpmuOfficer = $isSpmu && $user?->access_classification === \App\Enums\AccessClassification::SpmuOfficer;
    $isSpmuHead = $isSpmu && $user?->access_classification === \App\Enums\AccessClassification::SpmuHead;

    $version = $custody->request?->currentVersion;
    $scheduleDateValue = $version?->getAttribute('schedule_date') ?: $version?->getAttribute('needed_from');
    $returnDateValue = $version?->getAttribute('return_date')
        ?: $version?->getAttribute('return_due_at')
        ?: $custody->due_at;

    $scheduleDate = $scheduleDateValue ? \Illuminate\Support\Carbon::parse($scheduleDateValue) : null;
    $returnDate = $returnDateValue ? \Illuminate\Support\Carbon::parse($returnDateValue) : null;

    $outstandingTotal = $custody->lines->sum(
        fn ($line) => max(
            0,
            (float) $line->actual_released_quantity - (float) $line->returned_quantity
        )
    );

    $preparationComplete = (bool) $custody->prepared_at;
    $hasPickupSchedule = (bool) $custody->scheduled_release_at
        && (bool) $custody->pickup_expires_at
        && ! $custody->pickup_expired_at;

    $laundryJob = $custody->relationLoaded('laundryJob') ? $custody->laundryJob : null;
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Pickup / custody transaction</p>
        <h1>{{ $custody->custody_no }}</h1>
        <p>
            Request {{ $custody->request?->request_no }}
            @if(!$isBorrower && $custody->borrower)
                &middot; {{ $custody->borrower->full_name }}
            @endif
        </p>
    </div>
    <x-status-badge :status="$custody->status" />
</section>

@php
    $custodyStatusMessage = session('status');
    $duplicatePreparationMessage =
        'Prepared quantities verified. Borrower acknowledgement is now available.';
@endphp

@if($custodyStatusMessage && $custodyStatusMessage !== $duplicatePreparationMessage)
    <section class="content-area">
        <div class="callout success">{{ $custodyStatusMessage }}</div>
    </section>
@endif

@if($errors->any())
    <section class="content-area">
        <div class="callout danger">
            <strong>Please review the following:</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

<section class="content-grid two">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Approved schedule</p>
                <h2>Release Summary</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Schedule Date</dt>
            <dd>{{ $scheduleDate?->format('d F Y') ?: 'Not available' }}</dd>

            <dt>Expected Return Date</dt>
            <dd>{{ $returnDate?->format('d F Y') ?: 'Not available' }}</dd>

            <dt>Pickup</dt>
            <dd>{{ optional($custody->scheduled_release_at)->format('d F Y, g:i A') ?: 'Not scheduled' }}</dd>

            <dt>Pickup Expiration</dt>
            <dd>{{ optional($custody->pickup_expires_at)->format('d F Y, g:i A') ?: 'Not scheduled' }}</dd>

            <dt>Issued</dt>
            <dd>{{ optional($custody->released_at)->format('d F Y, g:i A') ?: 'Not yet issued' }}</dd>

            <dt>Outstanding</dt>
            <dd>{{ $outstandingTotal + 0 }} unit(s)</dd>
        </dl>


    </article>

    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Physical documents</p>
                <h2>Operational Forms</h2>
            </div>
        </div>

                @php
            /*
             * OPERATIONAL FORM CARDS
             *
             * These cards display the forms expected by the active
             * custody workflow. Actual PDF generation remains controlled
             * by the existing custody/document services.
             */

            $activeOperationalDocuments =
                $documents->whereNotIn(
                    'status',
                    ['SUPERSEDED', 'INVALIDATED', 'EXPIRED']
                );

            $borrowerSlipDocument =
                $activeOperationalDocuments->first(
                    fn ($document) =>
                        $document->document_type === 'BORROWER_SLIP'
                );

            $borrowerSlipEvidence =
                $borrowerSlipDocument
                    ? $borrowerSlipDocument
                        ->evidence()
                        ->with('file')
                        ->latest('id')
                        ->first()
                    : null;

            $laundryFormDocument =
                $activeOperationalDocuments->first(
                    fn ($document) =>
                        $document->document_type === 'LAUNDRY_FORM'
                );

            $gatePassDocument =
                $activeOperationalDocuments->first(
                    fn ($document) =>
                        $document->document_type === 'GATE_PASS'
                );

            /*
             * Determine which conditional operational forms apply
             * using the approved custody lines.
             */

            $hasLaundryProperty =
                $custody->lines->contains(
                    fn ($line) =>
                        (bool) $line->requestItem?->inventoryItem?->laundry_required
                        && (float) ($line->approved_quantity ?? 0) > 0
                );

            $hasOffCampusProperty =
                $custody->lines->contains(
                    fn ($line) =>
                        strtoupper(
                            (string) $line->requestItem?->use_location
                        ) === 'OFF_CAMPUS'
                        && (float) ($line->approved_quantity ?? 0) > 0
                );

            $operationalPendingText =
                $preparationComplete
                    ? 'Waiting for document generation'
                    : 'Waiting for SPMU preparation';
        @endphp


        <div class="operational-form-list">

            {{-- =================================================
                 BORROWER SLIP
            ================================================== --}}

            <article
                class="operational-form-card {{ $borrowerSlipDocument ? 'is-ready' : 'is-pending' }}"
            >
                <div class="operational-form-card__icon" aria-hidden="true">
                    @if($borrowerSlipDocument)
                        ✓
                    @else
                        1
                    @endif
                </div>

                <div class="operational-form-card__content">
                    <div class="operational-form-card__heading">
                        <strong>Borrower Slip</strong>

                        @if($borrowerSlipDocument)
                            <span class="operational-form-badge is-ready">
                                Ready
                            </span>
                        @else
                            <span class="operational-form-badge is-pending">
                                Pending
                            </span>
                        @endif
                    </div>

                    <p>
                        @if($borrowerSlipDocument)
                            Final operational form for the approved items and quantities.
                        @else
                            {{ $operationalPendingText }}
                        @endif
                    </p>

                    @if($borrowerSlipDocument)
                        <small>
                            {{ str($borrowerSlipDocument->status)->replace('_', ' ')->title() }}
                        </small>
                    @else
                        <small>
                            Required for every approved borrowing transaction.
                        </small>
                    @endif
                </div>

                @if($borrowerSlipDocument)
                    <a
                        class="button primary small ui-pressable operational-form-action"
                        href="{{ route('documents.download', $borrowerSlipDocument) }}"
                        target="_blank"
                        rel="noopener"
                    >
                        View / Print
                    </a>
                @endif
                            {{-- =================================================
                     ACCOMPLISHED BORROWER SLIP
                ================================================== --}}

                @if(
                    $borrowerSlipDocument
                    && $custody->released_at
                )
                    <div class="borrower-slip-accomplished">

                        <div class="borrower-slip-accomplished__header">
                            <div>
                                <strong>Accomplished copy</strong>


                            </div>

                            @if($borrowerSlipEvidence?->file)
                                <span class="borrower-slip-scan-badge is-uploaded">
                                    Uploaded
                                </span>
                            @else
                                <span class="borrower-slip-scan-badge is-pending">
                                    Not uploaded
                                </span>
                            @endif
                        </div>


                        @if($borrowerSlipEvidence?->file)

                            <div class="borrower-slip-accomplished__actions">

                                <a
                                    class="button secondary small ui-pressable"
                                    href="{{ route('files.show', $borrowerSlipEvidence->file, false) }}"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    View Scan
                                </a>


                                @if($isSpmuOfficer)
                                    <form
                                        method="post"
                                        action="{{ route('evidence.store', $borrowerSlipDocument) }}"
                                        enctype="multipart/form-data"
                                        class="borrower-slip-replace-form"
                                    >
                                        @csrf

                                        <label class="borrower-slip-file-control">
                                            <span>Replace scan</span>

                                            <input
                                                type="file"
                                                name="evidence"
                                                accept="application/pdf,image/png,image/jpeg,image/webp"
                                                required
                                            >
                                        </label>

                                        <button
                                            class="button primary small ui-pressable"
                                            type="submit"
                                        >
                                            Replace Scan
                                        </button>
                                    </form>
                                @endif

                            </div>

                        @elseif($isSpmuOfficer)

                            <form
                                method="post"
                                action="{{ route('evidence.store', $borrowerSlipDocument) }}"
                                enctype="multipart/form-data"
                                class="borrower-slip-upload-form"
                            >
                                @csrf

                                <label class="borrower-slip-file-control">


                                    <input
                                        type="file"
                                        name="evidence"
                                        accept="application/pdf,image/png,image/jpeg,image/webp"
                                        required
                                    >
                                </label>

                                <button
                                    class="button primary small ui-pressable"
                                    type="submit"
                                >
                                    Upload Copy
                                </button>
                            </form>

                        @endif

                    </div>
                @endif
</article>


            {{-- =================================================
                 LAUNDRY FORM
            ================================================== --}}

            @if($hasLaundryProperty)

                <article
                    class="operational-form-card {{ $laundryFormDocument ? 'is-ready' : 'is-pending' }}"
                >
                    <div class="operational-form-card__icon" aria-hidden="true">
                        @if($laundryFormDocument)
                            ✓
                        @else
                            2
                        @endif
                    </div>

                    <div class="operational-form-card__content">
                        <div class="operational-form-card__heading">
                            <strong>Laundry Form</strong>

                            @if($laundryFormDocument)
                                <span class="operational-form-badge is-ready">
                                    Ready
                                </span>
                            @else
                                <span class="operational-form-badge is-pending">
                                    Pending
                                </span>
                            @endif
                        </div>

                        <p>
                            @if($laundryFormDocument)
                                Required physical form for laundry processing.
                            @else
                                {{ $operationalPendingText }}
                            @endif
                        </p>

                        @if($laundryFormDocument)
                            <small>
                                {{ str($laundryFormDocument->status)->replace('_', ' ')->title() }}
                            </small>
                        @else
                            <small>
                                Required because this custody includes laundry / linen items.
                            </small>
                        @endif
                    </div>

                    @if($laundryFormDocument)
                        <a
                            class="button primary small ui-pressable operational-form-action"
                            href="{{ route('documents.download', $laundryFormDocument) }}"
                            target="_blank"
                            rel="noopener"
                        >
                            View / Print
                        </a>
                    @endif
                </article>

            @endif


            {{-- =================================================
                 GATE PASS
            ================================================== --}}

            @if($hasOffCampusProperty)

                <article
                    class="operational-form-card {{ $gatePassDocument ? 'is-ready' : 'is-pending' }}"
                >
                    <div class="operational-form-card__icon" aria-hidden="true">
                        @if($gatePassDocument)
                            ✓
                        @else
                            {{ $hasLaundryProperty ? '3' : '2' }}
                        @endif
                    </div>

                    <div class="operational-form-card__content">
                        <div class="operational-form-card__heading">
                            <strong>Gate Pass</strong>

                            @if($gatePassDocument)
                                <span class="operational-form-badge is-ready">
                                    Ready
                                </span>
                            @else
                                <span class="operational-form-badge is-pending">
                                    Pending
                                </span>
                            @endif
                        </div>

                        <p>
                            @if($gatePassDocument)
                                Physical gate document for approved off-campus property.
                            @else
                                {{ $operationalPendingText }}
                            @endif
                        </p>

                        @if($gatePassDocument)
                            <small>
                                {{ str($gatePassDocument->status)->replace('_', ' ')->title() }}
                            </small>
                        @else
                            <small>
                                Required because this custody includes off-campus property.
                            </small>
                        @endif
                    </div>

                    @if($gatePassDocument)
                        <a
                            class="button primary small ui-pressable operational-form-action"
                            href="{{ route('documents.download', $gatePassDocument) }}"
                            target="_blank"
                            rel="noopener"
                        >
                            View / Print
                        </a>
                    @endif
                </article>

            @endif

        </div>

    </article>
</section>

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Reserved / issued property</p>
                <h2>Items</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Approved / Reserved</th>
                        <th>Prepared</th>
                        <th>Issued</th>
                        <th>Returned</th>
                        <th>Outstanding</th>
                        <th>Condition</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($custody->lines as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                <small>{{ $line->requestItem->unit_snapshot }}</small>
                            </td>
                            <td>{{ $line->approved_quantity + 0 }}</td>
                            <td>{{ $line->quantity_to_receive + 0 }}</td>
                            <td>{{ $line->actual_released_quantity + 0 }}</td>
                            <td>{{ $line->returned_quantity + 0 }}</td>
                            <td>
                                {{ max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity) + 0 }}
                            </td>
                            <td>{{ $line->release_condition ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>

@if($isSpmuHead)
    <section class="content-area">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">SPMU Head oversight</p>
                    <h2>Operational release controls are assigned to the Action Officer</h2>
                </div>
            </div>
            <p>
                The SPMU Head can monitor the custody record. Pickup scheduling, physical preparation,
                issuance, return inspection, and supporting operational verification remain Action Officer tasks.
            </p>
        </article>
    </section>
@endif

@if($isSpmuOfficer && $custody->status === 'PREPARING_RELEASE' && !$custody->released_at)
    <section class="content-area custody-pickup-area">
        <form method="post" action="{{ route('custody.schedule-pickup', $custody) }}" class="card form-grid custody-action-card custody-pickup-card">
            @csrf
            <div class="card-header">
                <div>
                    <p class="eyebrow">SPMU pickup</p>
                    <h2>Pickup Schedule</h2>
                </div>
                <x-status-badge
                    :status="$hasPickupSchedule ? 'VERIFIED' : 'PENDING'"
                    :label="$hasPickupSchedule ? 'Scheduled' : 'Not scheduled'"
                />
            </div>

            <label>
                Pickup Date & Time
                <input
                    type="datetime-local"
                    name="pickup_at"
                    value="{{ old('pickup_at', optional($custody->scheduled_release_at)->format('Y-m-d\TH:i')) }}"
                    required
                >
            </label>

            <label>
                Pickup Expiration
                <input
                    type="datetime-local"
                    name="pickup_expires_at"
                    value="{{ old('pickup_expires_at', optional($custody->pickup_expires_at)->format('Y-m-d\TH:i')) }}"
                    required
                >
            </label>

                                    <p class="meta custody-compact-helper">
                Pickup and expiration must be on the same date.
            </p>

            <div class="custody-save-schedule-row">
                <button class="button primary ui-pressable custody-compact-action custody-save-schedule">
                    Save Schedule
                </button>
            </div>
        </form>


    </section>

                @unless($preparationComplete)
                <form
                    id="custody-prepare-form"
                    method="post"
                    action="{{ route('custody.prepare', $custody) }}"
                    class="custody-hidden-prepare-form"
                >
                    @csrf
                </form>
            @endunless
<section class="content-area">
        <form method="post" action="{{ route('custody.quantities', $custody) }}" class="card form-grid custody-prepared-card">
            @csrf
            <div class="card-header">
                <div>
                    <p class="eyebrow">Release quantities</p>
                    <h2>Prepared Quantities</h2>
                </div>
            </div>

                        <div class="custody-preparation-inline">
                <div class="custody-preparation-inline__main">
                    @if($preparationComplete)
                        <span class="custody-preparation-inline__icon is-complete" aria-hidden="true">✓</span>

                        <div class="custody-preparation-inline__copy">
                            <strong>Preparation completed</strong>
                            <span>Prepared quantities are ready for borrower confirmation.</span>
                        </div>
                    @elseif($hasPickupSchedule)
                        <span class="custody-preparation-inline__icon is-ready" aria-hidden="true">✓</span>

                        <div class="custody-preparation-inline__copy">
                            <strong>Ready to prepare</strong>
                            <span>Review the final quantities below, then confirm preparation.</span>
                        </div>
                    @else
                        <span class="custody-preparation-inline__icon is-pending" aria-hidden="true">!</span>

                        <div class="custody-preparation-inline__copy">
                            <strong>Pickup schedule required</strong>
                            <span>Set the pickup schedule before confirming preparation.</span>
                        </div>
                    @endif
                </div>

                <x-status-badge
                    :status="$preparationComplete ? 'VERIFIED' : 'PENDING'"
                    :label="$preparationComplete ? 'Prepared' : ($hasPickupSchedule ? 'Ready to prepare' : 'Pending')"
                />
            </div>
<div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Approved</th>
                            <th>Prepared</th>

                        </tr>
                    </thead>
                    <tbody>
                        @foreach($custody->lines as $line)
                            <tr>
                                <td>
                                    <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                    <small>{{ $line->requestItem->unit_snapshot }}</small>
                                </td>
                                <td>{{ $line->approved_quantity + 0 }}</td>
                                <td>
                                    <input
                                        type="number"
                                        step="1"
                                        min="0"
                                        max="{{ (int) $line->approved_quantity }}"
                                        name="quantities[{{ $line->id }}]"
                                        value="{{ old('quantities.'.$line->id, (int) $line->quantity_to_receive) }}"
                                        required
                                    >
                                </td>

                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="custody-quantity-actions">
                <button
                    type="submit"
                    class="button primary ui-pressable custody-save-quantities"
                >
                    Save Quantities
                </button>

                @unless($preparationComplete)
                    <button
                        type="submit"
                        form="custody-prepare-form"
                        class="button primary ui-pressable custody-confirm-preparation"
                        @disabled(!$hasPickupSchedule)
                    >
                        Confirm Preparation
                    </button>
                @endunless
            </div>
        </form>
    </section>
@endif

@if($isBorrower && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at && !$custody->acknowledged_at)
    <section class="content-area">
        <form
            method="post"
            action="{{ route('custody.acknowledge', $custody) }}"
            class="card borrower-prepared-review"
        >
            @csrf

            <div class="card-header borrower-prepared-review__header">
                <div>
                    <p class="eyebrow">Borrower confirmation</p>
                    <h2>Review Prepared Quantities</h2>
                </div>

                <x-status-badge
                    status="PENDING"
                    label="Action required"
                />
            </div>

            <div class="borrower-prepared-summary">
                <div class="borrower-prepared-summary__item">
                    <span>Pickup</span>
                    <strong>
                        {{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}
                    </strong>
                </div>

                <div class="borrower-prepared-summary__item">
                    <span>Pickup Until</span>
                    <strong>
                        {{ optional($custody->pickup_expires_at)->format('d M Y, g:i A') ?: '—' }}
                    </strong>
                </div>

                <div class="borrower-prepared-summary__item">
                    <span>Item Types</span>
                    <strong>{{ $custody->lines->count() }}</strong>
                </div>
            </div>

            <div class="borrower-prepared-intro">
                <span class="borrower-prepared-intro__icon" aria-hidden="true">✓</span>

                <div>
                    <strong>SPMU has prepared the final release quantities.</strong>
                    <span>
                        Review the quantities below before confirming.
                    </span>
                </div>
            </div>

            <div class="table-wrap borrower-prepared-table">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Unit</th>
                            <th>Approved</th>
                            <th>Prepared</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($custody->lines as $line)
                            <tr>
                                <td>
                                    <strong>
                                        {{ $line->requestItem->description_snapshot }}
                                    </strong>
                                </td>

                                <td>
                                    {{ $line->requestItem->unit_snapshot }}
                                </td>

                                <td>
                                    {{ $line->approved_quantity + 0 }}
                                </td>

                                <td>
                                    <strong class="borrower-prepared-qty">
                                        {{ (int) $line->quantity_to_receive }}
                                    </strong>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="borrower-prepared-footer">
                <p class="borrower-prepared-note">

                </p>

                <button
                    type="submit"
                    class="button primary ui-pressable borrower-confirm-prepared"
                >
                    Confirm Prepared Quantities
                </button>
            </div>
        </form>
    </section>
@endif

@if($isBorrower && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at && $custody->acknowledged_at && !$custody->released_at)
    <section class="content-area">
        <div class="custody-prepared-confirmed-notice" role="status">
    <span class="custody-prepared-confirmed-notice__icon" aria-hidden="true">✓</span>

    <div class="custody-prepared-confirmed-notice__content">
        <strong>Prepared quantities confirmed</strong>
        <p>Your system confirmation has been recorded. Please proceed with the physical handover.</p>

    </div>

    <span class="custody-prepared-confirmed-notice__badge">Confirmed</span>
</div>
    </section>
@endif

@if($isSpmuOfficer && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at && !$custody->acknowledged_at)
    <section class="content-area">
        <div
            class="custody-borrower-confirmation-notice"
            role="status"
        >
            <span
                class="custody-borrower-confirmation-notice__icon"
                aria-hidden="true"
            >
                !
            </span>

            <div class="custody-borrower-confirmation-notice__content">
                <strong>Borrower confirmation required</strong>

                <p>
                    Final quantities are prepared. The borrower must review
                    and confirm them before SPMU can record the physical release.
                </p>

                <small>
                    System confirmation only. Required signatures remain
                    handwritten on the printed physical documents.
                </small>
            </div>
        </div>
    </section>
@endif

@if($isSpmuOfficer && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at && $custody->acknowledged_at)

@endif

@if($laundryJob)
    <section class="content-area">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Laundry workflow</p>
                    <h2>Laundry Return Status</h2>
                </div>
                <x-status-badge :status="$laundryJob->status" />
            </div>

            <p>

            </p>

            @if($laundryJob->latestEvidence)
                <div class="evidence-row top-gap">
                    <div>
                        <strong>Accomplished Laundry Form</strong>
                        <small>
                            Uploaded {{ optional($laundryJob->latestEvidence->submitted_at)->format('d M Y, g:i A') ?: '—' }}
                            &middot; {{ str($laundryJob->latestEvidence->verification_status)->replace('_', ' ')->title() }}
                        </small>
                    </div>

                    @if($laundryJob->latestEvidence->file)
                        <a
                            class="button secondary small ui-pressable"
                            href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}"
                            target="_blank"
                            rel="noopener"
                        >
                            View Uploaded Scan
                        </a>
                    @endif
                </div>

                @if($laundryJob->latestEvidence->rejection_reason)
                    <div class="callout warning top-gap">
                        <strong>Replacement scan requested</strong>
                        <p>{{ $laundryJob->latestEvidence->rejection_reason }}</p>
                    </div>
                @endif
            @else
                <div class="callout warning top-gap">
                    <strong>Laundry Form pending</strong>
                    <p>
                        Complete and upload the accomplished Laundry Form from the Laundry workspace.
                    </p>
                </div>
            @endif

            @if($isSpmuHead && $laundryJob->latestEvidence?->verification_status === 'PENDING_VERIFICATION')
                <div class="callout info top-gap">
                    <strong>Awaiting SPMU Action Officer verification.</strong>
                    <p>
                        You can monitor and view the uploaded scan here. Operational verification and
                        transcription of the accomplished Laundry Form are assigned to the SPMU Action Officer.
                    </p>
                </div>
            @endif
        </article>
    </section>

    @if(
        $isSpmuOfficer
        && $laundryJob->latestEvidence
        && $laundryJob->latestEvidence->verification_status === 'PENDING_VERIFICATION'
    )
        <section class="content-grid two">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Laundry evidence</p>
                        <h2>Review accomplished Laundry Form</h2>
                    </div>
                    <x-status-badge status="PENDING_VERIFICATION" />
                </div>

                <p>
                    Compare the uploaded scan with the physical linen transaction. Encode only what is
                    written on the accomplished form. Do not add or infer findings that are not on the form.
                </p>

                @if($laundryJob->latestEvidence->file)
                    <a
                        class="button secondary ui-pressable"
                        href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}"
                        target="_blank"
                        rel="noopener"
                    >
                        Open Accomplished Laundry Form
                    </a>
                @endif
            </article>

            <form
                method="post"
                action="{{ route('laundry.verify-form', $laundryJob) }}"
                class="card form-grid"
            >
                @csrf
                <input type="hidden" name="decision" value="VERIFIED">

                <div class="card-header">
                    <div>
                        <p class="eyebrow">SPMU Action Officer</p>
                        <h2>Verify and encode Laundry inspection</h2>
                    </div>
                </div>

                <label>
                    Laundry Worker Name
                    <input
                        name="worker_name"
                        value="{{ old('worker_name', $laundryJob->worker_name) }}"
                        required
                    >
                </label>

                <div class="content-grid two">
                    <label>
                        Linen Received by Laundry
                        <input
                            type="datetime-local"
                            name="worker_received_at"
                            value="{{ old(
                                'worker_received_at',
                                optional($laundryJob->worker_received_at)->format('Y-m-d\\TH:i')
                            ) }}"
                            required
                        >
                    </label>

                    <label>
                        Laundry Completed
                        <input
                            type="datetime-local"
                            name="worker_completed_at"
                            value="{{ old(
                                'worker_completed_at',
                                optional($laundryJob->worker_completed_at)->format('Y-m-d\\TH:i')
                            ) }}"
                            required
                        >
                    </label>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Issued</th>
                                <th>Received</th>
                                <th>Finding</th>
                                <th>Affected</th>
                                <th>Completed</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($laundryJob->lines as $line)
                                <tr>
                                    <td>
                                        <strong>{{ $line->custodyLine->requestItem->description_snapshot }}</strong>
                                        <small>{{ $line->custodyLine->requestItem->unit_snapshot }}</small>
                                    </td>
                                    <td>{{ $line->issued_quantity + 0 }}</td>
                                    <td>
                                        <input
                                            type="number"
                                            step="0.001"
                                            min="0"
                                            max="{{ $line->issued_quantity }}"
                                            name="lines[{{ $line->id }}][received_quantity]"
                                            value="{{ old(
                                                'lines.'.$line->id.'.received_quantity',
                                                $line->received_quantity ?? $line->issued_quantity
                                            ) }}"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <select
                                            name="lines[{{ $line->id }}][issue_type]"
                                            required
                                        >
                                            @foreach([
                                                'NONE' => 'No issue',
                                                'STAINED' => 'Stained',
                                                'TORN' => 'Torn',
                                                'DAMAGED' => 'Damaged',
                                                'OTHER' => 'Other',
                                            ] as $value => $label)
                                                <option
                                                    value="{{ $value }}"
                                                    @selected(
                                                        old(
                                                            'lines.'.$line->id.'.issue_type',
                                                            $line->issue_type ?? 'NONE'
                                                        ) === $value
                                                    )
                                                >
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="0.001"
                                            min="0"
                                            max="{{ $line->issued_quantity }}"
                                            name="lines[{{ $line->id }}][affected_quantity]"
                                            value="{{ old(
                                                'lines.'.$line->id.'.affected_quantity',
                                                $line->affected_quantity ?? 0
                                            ) }}"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="0.001"
                                            min="0"
                                            max="{{ $line->issued_quantity }}"
                                            name="lines[{{ $line->id }}][completed_quantity]"
                                            value="{{ old(
                                                'lines.'.$line->id.'.completed_quantity',
                                                $line->completed_quantity ?? $line->issued_quantity
                                            ) }}"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <input
                                            name="lines[{{ $line->id }}][remarks]"
                                            value="{{ old(
                                                'lines.'.$line->id.'.remarks',
                                                $line->remarks
                                            ) }}"
                                        >
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <label>
                    Laundry Worker Remarks
                    <textarea name="worker_remarks">{{ old(
                        'worker_remarks',
                        $laundryJob->worker_remarks
                    ) }}</textarea>
                </label>

                <button class="button primary ui-pressable">
                    Verify Laundry Form & Save Inspection
                </button>
            </form>
        </section>

        <section class="content-area narrow">
            <form
                method="post"
                action="{{ route('laundry.verify-form', $laundryJob) }}"
                class="card form-grid"
            >
                @csrf
                <input type="hidden" name="decision" value="REJECTED">

                <div class="card-header">
                    <div>
                        <p class="eyebrow">Unreadable / incorrect scan</p>
                        <h2>Request replacement Laundry Form</h2>
                    </div>
                </div>

                <label>
                    Reason for replacement
                    <textarea
                        name="rejection_reason"
                        required
                    >{{ old('rejection_reason') }}</textarea>
                </label>

                <button class="button danger ui-pressable">
                    Request Replacement Scan
                </button>
            </form>
        </section>
    @elseif(
        $isSpmu
        && $laundryJob->latestEvidence
        && $laundryJob->latestEvidence->verification_status === 'VERIFIED'
    )
        <section class="content-area">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Laundry verification</p>
                        <h2>Accomplished form verified</h2>
                    </div>
                    <x-status-badge status="VERIFIED" />
                </div>

                <dl class="detail-list">
                    <dt>Laundry Worker</dt>
                    <dd>{{ $laundryJob->worker_name ?: '—' }}</dd>

                    <dt>Received by Laundry</dt>
                    <dd>{{ optional($laundryJob->worker_received_at)->format('d M Y, g:i A') ?: '—' }}</dd>

                    <dt>Laundry Completed</dt>
                    <dd>{{ optional($laundryJob->worker_completed_at)->format('d M Y, g:i A') ?: '—' }}</dd>

                    <dt>Verified by SPMU</dt>
                    <dd>{{ $laundryJob->formVerifier?->full_name ?: '—' }}</dd>

                    <dt>Verified at</dt>
                    <dd>{{ optional($laundryJob->form_verified_at)->format('d M Y, g:i A') ?: '—' }}</dd>
                </dl>

                @if($laundryJob->worker_remarks)
                    <div class="callout info top-gap">
                        <strong>Laundry remarks</strong>
                        <p>{{ $laundryJob->worker_remarks }}</p>
                    </div>
                @endif

                <div class="table-wrap top-gap">
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Issued</th>
                                <th>Received</th>
                                <th>Finding</th>
                                <th>Affected</th>
                                <th>Completed</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($laundryJob->lines as $line)
                                <tr>
                                    <td>
                                        <strong>{{ $line->custodyLine->requestItem->description_snapshot }}</strong>
                                        <small>{{ $line->custodyLine->requestItem->unit_snapshot }}</small>
                                    </td>
                                    <td>{{ $line->issued_quantity + 0 }}</td>
                                    <td>{{ ($line->received_quantity ?? 0) + 0 }}</td>
                                    <td>{{ str($line->issue_type ?: 'NONE')->replace('_', ' ')->title() }}</td>
                                    <td>{{ ($line->affected_quantity ?? 0) + 0 }}</td>
                                    <td>{{ ($line->completed_quantity ?? 0) + 0 }}</td>
                                    <td>{{ $line->remarks ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    @endif
@endif

@if($isSpmuOfficer && $custody->released_at && $outstandingTotal > 0)
    <section class="content-area">
        <form
            method="post"
            action="{{ route('custody.return', $custody) }}"
            enctype="multipart/form-data"
            class="card form-grid"
        >
            @csrf

            <div class="card-header">
                <div>
                    <p class="eyebrow">Physical return inspection</p>
                    <h2>Return Inspection</h2>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Outstanding</th>
                            <th>Returned Qty</th>
                            <th>Condition / Finding</th>
                            <th>Evidence</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($custody->lines as $line)
                            @php
                                $outstanding = max(
                                    0,
                                    (float) $line->actual_released_quantity - (float) $line->returned_quantity
                                );
                            @endphp

                            @if($outstanding > 0)
                                <tr>
                                    <td>
                                        <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                        <small>{{ $line->requestItem->unit_snapshot }}</small>
                                    </td>
                                    <td>{{ $outstanding + 0 }}</td>
                                    <td>
                                        <input
                                            type="number"
                                            step="0.001"
                                            min="0"
                                            max="{{ $outstanding }}"
                                            name="quantities[{{ $line->id }}]"
                                            value="{{ old('quantities.'.$line->id, 0) }}"
                                        >
                                    </td>
                                    <td>
                                        <select name="conditions[{{ $line->id }}]">
                                            <option value="FINE">Fine / Good</option>
                                            <option value="DAMAGED">Damaged</option>
                                            <option value="DESTROYED">Destroyed</option>
                                            <option value="MISSING">Missing</option>
                                            <option value="LOST">Lost</option>
                                            <option value="STOLEN">Stolen / Reported Stolen</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input
                                            type="file"
                                            name="evidence_files[{{ $line->id }}]"
                                            accept="application/pdf,image/png,image/jpeg,image/webp"
                                        >
                                    </td>
                                    <td>
                                        <input
                                            name="police_blotter_references[{{ $line->id }}]"
                                            value="{{ old('police_blotter_references.'.$line->id) }}"
                                        >
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <label>
                Return Remarks
                <textarea name="remarks">{{ old('remarks') }}</textarea>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="early_return" value="1" @checked(old('early_return'))>
                Mark as early return
            </label>

            <button class="button primary ui-pressable">Record Physical Return</button>
        </form>
    </section>
@endif

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Return history</p>
                <h2>Return History</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Return</th>
                        <th>Received</th>
                        <th>Type</th>
                        <th>Status</th>
                            <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $custody->returns->loadMissing(
                            'lines.custodyLine.requestItem'
                        );
                    @endphp

                    @forelse($custody->returns->sortByDesc('id') as $return)
                        @php
                            $returnDetailId = 'return-details-'.$return->id;
                            $returnLineCount = $return->lines->count();
                            $returnQuantityTotal = $return->lines->sum(
                                fn ($line) => (float) $line->quantity_received
                            );
                        @endphp

                        <tr
                            class="return-history-summary-row"
                            data-return-history-row
                            data-return-details="{{ $returnDetailId }}"
                        >
                            <td>
                                <button
                                    type="button"
                                    class="return-history-toggle"
                                    aria-expanded="false"
                                    aria-controls="{{ $returnDetailId }}"
                                >
                                    <span>{{ $return->return_no }}</span>

                                    <svg
                                        class="return-history-toggle__chevron"
                                        viewBox="0 0 20 20"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="M6.5 8 10 11.5 13.5 8"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </svg>
                                </button>
                            </td>

                            <td>
                                {{ optional($return->received_at)->format('d M Y, g:i A') ?: 'â€”' }}
                            </td>

                            <td>
                                {{ str($return->return_type ?: 'NORMAL')->replace('_', ' ')->title() }}
                            </td>

                            <td>
                                <x-status-badge :status="$return->status" />
                            </td>

                            <td>
                                <span class="return-history-remarks-preview">
                                    {{ $return->remarks ?: '' }}
                                </span>
                            </td></tr>

                        <tr
                            id="{{ $returnDetailId }}"
                            class="return-history-detail-row"
                            hidden
                        >
                            <td colspan="5">
                                <div class="return-history-detail-panel">
                                    <div class="return-history-detail-header">
                                        <div>
                                            <p class="eyebrow">Returned property</p>
                                            <h3>Return Details</h3>
                                        </div>

                                        <div class="return-history-detail-summary">
                                            <span>
                                                {{ $returnLineCount }}
                                                {{ str('item type')->plural($returnLineCount) }}
                                            </span>

                                            <span>
                                                {{ $returnQuantityTotal + 0 }} total unit(s)
                                            </span>
                                        </div>
                                    </div>

                                    @if($return->lines->isNotEmpty())
                                        <div class="return-history-items-wrap">
                                            <table class="return-history-items-table">
                                                <thead>
                                                    <tr>
                                                        <th>Item</th>
                                                        <th>Returned Quantity</th>
                                                        <th>Condition / Finding</th>
                                                        <th>Disposition</th>
                                                    </tr>
                                                </thead>

                                                <tbody>
                                                    @foreach($return->lines as $returnLine)
                                                        @php
                                                            $returnedItem = $returnLine->custodyLine?->requestItem;
                                                            $conditionCode = strtoupper(
                                                                (string) ($returnLine->condition_code ?: 'NOT_RECORDED')
                                                            );
                                                        @endphp

                                                        <tr>
                                                            <td>
                                                                <strong>
                                                                    {{ $returnedItem?->description_snapshot ?: 'Item unavailable' }}
                                                                </strong>

                                                                <small>
                                                                    {{ $returnedItem?->unit_snapshot ?: 'Unit not recorded' }}
                                                                </small>
                                                            </td>

                                                            <td>
                                                                <strong class="return-history-quantity">
                                                                    {{ ($returnLine->quantity_received ?? 0) + 0 }}
                                                                </strong>
                                                            </td>

                                                            <td>
                                                                <span
                                                                    class="return-history-condition is-{{ strtolower($conditionCode) }}"
                                                                >
                                                                    {{ str($conditionCode)->replace('_', ' ')->title() }}
                                                                </span>
                                                            </td>

                                                            <td>
                                                                {{ str($returnLine->disposition_state ?: 'NOT_RECORDED')->replace('_', ' ')->title() }}
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @else
                                        <div class="return-history-detail-empty">
                                            No item-level details were recorded for this return.
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">No returns recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>

@if($isBorrower && $custody->released_at && $outstandingTotal > 0)
    <section class="content-area">
        <form method="post" action="{{ route('custody.early-return', $custody) }}" class="card form-grid">
            @csrf

            <div class="card-header">
                <div>
                    <p class="eyebrow">Borrower notice</p>
                    <h2>Early Return Notice</h2>
                </div>
            </div>

            <label>
                Proposed Handover Date & Time
                <input type="datetime-local" name="proposed_return_at" required>
            </label>

            @foreach($custody->lines as $line)
                @php
                    $outstanding = max(
                        0,
                        (float) $line->actual_released_quantity - (float) $line->returned_quantity
                    );
                @endphp

                @if($outstanding > 0)
                    <label>
                        {{ $line->requestItem->description_snapshot }} — outstanding {{ $outstanding + 0 }}
                        <input
                            type="number"
                            step="0.001"
                            min="0"
                            max="{{ $outstanding }}"
                            name="quantities[{{ $line->id }}]"
                            value="0"
                        >
                    </label>
                @endif
            @endforeach

            <label>
                Reason / Coordination Note
                <textarea name="reason"></textarea>
            </label>

            <button class="button primary ui-pressable">Send Early Return Notice</button>
        </form>
    </section>
@endif

<style>
/* OPERATIONAL FORMS UX */

.operational-form-list {
    display: grid;
    gap: 10px;
}


/* ============================================================
   CARD
   ============================================================ */

.operational-form-card {
    display: grid;

    grid-template-columns:
        38px
        minmax(0, 1fr)
        auto;

    align-items: center;

    gap: 12px;

    padding: 13px 14px;

    border: 1px solid #d8e2ed;
    border-radius: 10px;

    background: #ffffff;

    transition:
        border-color 150ms ease,
        background-color 150ms ease,
        box-shadow 150ms ease;
}


/* Pending */

.operational-form-card.is-pending {
    background: #f8fafc;
}


/* Ready */

.operational-form-card.is-ready {
    border-color: #c7e5d5;
    background: #f3faf6;
}


/* ============================================================
   ICON
   ============================================================ */

.operational-form-card__icon {
    width: 34px;
    height: 34px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border: 1px solid #c9d5e2;
    border-radius: 999px;

    background: #eef3f8;
    color: #64768d;

    font-size: 12px;
    font-weight: 800;
}


.operational-form-card.is-ready
.operational-form-card__icon {
    border-color: #23855b;

    background: #23855b;
    color: #ffffff;
}


/* ============================================================
   CONTENT
   ============================================================ */

.operational-form-card__content {
    min-width: 0;
}


.operational-form-card__heading {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: 8px;

    margin-bottom: 3px;
}


.operational-form-card__heading strong {
    color: #152d4c;

    font-size: 14px;
}


.operational-form-card__content p {
    margin: 0;

    color: #596c83;

    font-size: 12px;
    line-height: 1.45;
}


.operational-form-card__content small {
    display: block;

    margin-top: 4px;

    color: #7a899b;

    font-size: 11px;
}


/* ============================================================
   STATUS BADGES
   ============================================================ */

.operational-form-badge {
    display: inline-flex;
    align-items: center;

    min-height: 22px;

    padding: 2px 8px;

    border-radius: 999px;

    font-size: 10px;
    font-weight: 800;
}


.operational-form-badge.is-pending {
    background: #edf1f6;
    color: #66788e;
}


.operational-form-badge.is-ready {
    background: #dff3e8;
    color: #176b49;
}


/* ============================================================
   ACTION
   ============================================================ */

.operational-form-action {
    min-width: 100px;

    justify-content: center;

    white-space: nowrap;

    background: #1769e0 !important;
    border-color: #1769e0 !important;
    color: #ffffff !important;
}


.operational-form-action:hover {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
    color: #ffffff !important;
}


.operational-form-note {
    margin-top: 12px;

    line-height: 1.45;
}


/* ============================================================
   DARK THEME
   ============================================================ */

html[data-theme="dark"]
.operational-form-card {
    border-color: rgba(255,255,255,.10);

    background: rgba(255,255,255,.03);
}


html[data-theme="dark"]
.operational-form-card.is-ready {
    border-color: rgba(63, 175, 116, .28);

    background: rgba(35, 133, 91, .12);
}


html[data-theme="dark"]
.operational-form-card__heading strong {
    color: #edf4ff;
}


html[data-theme="dark"]
.operational-form-card__content p {
    color: #bac8d9;
}


html[data-theme="dark"]
.operational-form-card__content small {
    color: #91a3b8;
}


/* ============================================================
   MOBILE
   ============================================================ */

@media (max-width: 700px) {

    .operational-form-card {
        grid-template-columns:
            36px
            minmax(0, 1fr);
    }

    .operational-form-action {
        grid-column: 1 / -1;

        width: 100%;
    }
}
</style>

<script>
/* PICKUP EXPIRATION SAME-DATE GUARD */

document.addEventListener('DOMContentLoaded', function () {
    const pickupInput =
        document.querySelector('input[name="pickup_at"]');

    const expirationInput =
        document.querySelector('input[name="pickup_expires_at"]');

    if (!pickupInput || !expirationInput) {
        return;
    }

    const pickupForm =
        pickupInput.closest('form');

    function datePart(value) {
        return value && value.length >= 10
            ? value.slice(0, 10)
            : '';
    }

    function timePart(value) {
        return value && value.length >= 16
            ? value.slice(11, 16)
            : '';
    }

    function synchronizeExpirationDate() {
        const pickupDate = datePart(pickupInput.value);

        if (!pickupDate) {
            expirationInput.removeAttribute('min');
            expirationInput.removeAttribute('max');
            return;
        }

        /*
         * The expiration cannot be earlier than the pickup
         * and cannot move into the following calendar date.
         */
        expirationInput.min = pickupInput.value;
        expirationInput.max = pickupDate + 'T23:59';

        /*
         * Preserve the user's chosen expiration TIME,
         * but force its DATE to match the pickup date.
         */
        if (expirationInput.value) {
            const expirationTime =
                timePart(expirationInput.value);

            const expirationDate =
                datePart(expirationInput.value);

            if (
                expirationTime &&
                expirationDate !== pickupDate
            ) {
                expirationInput.value =
                    pickupDate + 'T' + expirationTime;
            }
        }
    }

    function synchronizeFromExpiration() {
        const pickupDate =
            datePart(pickupInput.value);

        const expirationTime =
            timePart(expirationInput.value);

        if (
            pickupDate &&
            expirationTime &&
            datePart(expirationInput.value) !== pickupDate
        ) {
            expirationInput.value =
                pickupDate + 'T' + expirationTime;
        }

        synchronizeExpirationDate();
    }

    /*
     * Fix an invalid old() value immediately after
     * a previous failed validation attempt.
     */
    synchronizeExpirationDate();

    pickupInput.addEventListener(
        'change',
        synchronizeExpirationDate
    );

    pickupInput.addEventListener(
        'input',
        synchronizeExpirationDate
    );

    expirationInput.addEventListener(
        'change',
        synchronizeFromExpiration
    );

    /*
     * Last safety sync before normal browser validation
     * and Laravel submission.
     */
    if (pickupForm) {
        pickupForm.addEventListener(
            'submit',
            function () {
                synchronizeExpirationDate();
            }
        );
    }
});
</script>

<style>
/* CUSTODY RELEASE CLEAN UI V2 */


/* ============================================================
   PICKUP + PREPARATION
============================================================ */

.custody-release-action-grid {
    gap: 16px;
    align-items: stretch;
}

.custody-action-card {
    display: flex !important;
    flex-direction: column;

    min-height: 0;

    gap: 14px;
}

.custody-action-card .card-header {
    margin-bottom: 0;
}

.custody-action-card label {
    margin: 0;
}

.custody-compact-helper {
    margin: 0;

    color: #687b91;

    font-size: 11px;
    line-height: 1.45;
}


/* Primary actions */

.custody-action-card > .button,
.custody-save-quantities {
    min-height: 42px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    background: #1769e0 !important;
    border-color: #1769e0 !important;
    color: #ffffff !important;

    box-shadow:
        0 2px 7px rgba(23, 105, 224, .16);
}

.custody-action-card > .button {
    width: 100%;
    margin-top: auto;
}

.custody-action-card > .button:hover:not(:disabled),
.custody-save-quantities:hover:not(:disabled) {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
    color: #ffffff !important;
}

.custody-action-card > .button:disabled {
    background: #e8edf3 !important;
    border-color: #d6dee8 !important;
    color: #8998aa !important;

    box-shadow: none;
}


/* ============================================================
   PREPARED QUANTITIES
============================================================ */

.custody-prepared-card {
    gap: 12px;
}

.custody-prepared-card .card-header {
    margin-bottom: 0;
}

.custody-prepared-card table {
    table-layout: auto;
}

.custody-prepared-card th,
.custody-prepared-card td {
    padding-top: 10px;
    padding-bottom: 10px;
}

.custody-prepared-card th:nth-child(2),
.custody-prepared-card td:nth-child(2) {
    width: 155px;
}

.custody-prepared-card th:nth-child(3),
.custody-prepared-card td:nth-child(3) {
    width: 180px;
}

.custody-prepared-card input[type="number"] {
    width: 100%;
    max-width: 140px;

    min-height: 40px;
}

.custody-save-quantities {
    width: auto;

    min-width: 145px;

    align-self: flex-start;
}


/* ============================================================
   OPERATIONAL FORMS
============================================================ */

.operational-form-list {
    gap: 8px;
}

.operational-form-card {
    padding: 11px 13px;
}


/* ============================================================
   RESPONSIVE
============================================================ */

@media (max-width: 760px) {

    .custody-release-action-grid {
        grid-template-columns: 1fr;
    }

    .custody-prepared-card th:nth-child(2),
    .custody-prepared-card td:nth-child(2),
    .custody-prepared-card th:nth-child(3),
    .custody-prepared-card td:nth-child(3) {
        width: auto;
    }

    .custody-prepared-card input[type="number"] {
        max-width: none;
    }

    .custody-save-quantities {
        width: 100%;
    }
}

/* ============================================================
   CUSTODY PROFESSIONAL COMPACT ACTIONS
   Desktop: compact contextual actions
   Mobile: full-width actions
============================================================ */

/*
 * Each card should size itself from its own content.
 * This prevents the Preparation card from stretching to the
 * height of the Pickup Schedule card.
 */
.custody-release-action-grid {
    align-items: start !important;
}

.custody-release-action-grid > * {
    align-self: start;
}

.custody-action-card {
    height: auto !important;
    min-height: 0 !important;
}


/* ------------------------------------------------------------
   Compact desktop actions
------------------------------------------------------------ */

.custody-action-card > .button,
.custody-save-quantities,
.custody-physical-release-card > .button,
.custody-compact-action {
    width: auto !important;
    min-width: 150px;
    max-width: 220px;
    min-height: 40px;

    padding: 0 18px;

    justify-self: end;
    align-self: flex-end;

    margin-top: 2px;

    border-radius: 8px;
    font-weight: 700;
}


/*
 * form-grid stretches children by default.
 * Explicitly anchor Save Quantities to the lower-right.
 */
.custody-prepared-card .custody-save-quantities {
    justify-self: end !important;
    align-self: center;
}


/* ------------------------------------------------------------
   Preparation state
------------------------------------------------------------ */

.custody-preparation-summary {
    display: flex;
    align-items: flex-start;

    gap: 10px;

    width: 100%;
    box-sizing: border-box;

    padding: 12px 14px;

    border: 1px solid #d8e3ef;
    border-radius: 10px;

    background: #f8fafc;
}

.custody-preparation-summary__icon {
    flex: 0 0 26px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 26px;
    height: 26px;

    border-radius: 999px;

    background: #e8f7ef;
    color: #14804a;

    font-size: 13px;
    font-weight: 800;
}

.custody-preparation-summary__icon.is-ready {
    background: #eaf3ff;
    color: #1769e0;
}

.custody-preparation-summary__icon.is-pending {
    background: #fff5dc;
    color: #9a6700;
}

.custody-preparation-summary__content {
    min-width: 0;

    display: flex;
    flex-direction: column;

    gap: 3px;

    padding-top: 1px;
}

.custody-preparation-summary__content strong {
    color: #102a43;

    font-size: 13px;
    line-height: 1.3;
}

.custody-preparation-summary__content small {
    color: #61758a;

    font-size: 12px;
    line-height: 1.45;
}


/* ------------------------------------------------------------
   Prepared quantities
------------------------------------------------------------ */

.custody-prepared-card {
    gap: 14px;
}

.custody-prepared-card .table-wrap {
    margin-bottom: 0;
}

.custody-prepared-card input[type="number"] {
    max-width: 120px;
}


/* ------------------------------------------------------------
   Physical release
------------------------------------------------------------ */

.custody-physical-release-card {
    gap: 14px;
}

.custody-physical-release-card textarea {
    min-height: 82px !important;
    height: 82px;
    max-height: 120px;
    resize: vertical;
}


/* ------------------------------------------------------------
   Mobile
------------------------------------------------------------ */

@media (max-width: 760px) {
    .custody-action-card > .button,
    .custody-save-quantities,
    .custody-physical-release-card > .button,
    .custody-compact-action {
        width: 100% !important;
        max-width: none;

        justify-self: stretch;
        align-self: stretch;
    }

    .custody-preparation-summary {
        padding: 11px 12px;
    }

    .custody-prepared-card input[type="number"] {
        max-width: none;
    }
}

/* ============================================================
   CUSTODY FINAL PROFESSIONAL UX
============================================================ */

/* Prevent the two cards from stretching to identical heights. */
.custody-release-action-grid {
    align-items: start !important;
}

.custody-release-action-grid > .card,
.custody-release-action-grid > form {
    align-self: start !important;
    height: auto !important;
    min-height: 0 !important;
}


/* ------------------------------------------------------------
   Desktop action buttons
------------------------------------------------------------ */

.custody-action-card > .button,
.custody-compact-action,
.custody-save-quantities,
.custody-physical-release-card > .button {
    width: auto !important;

    min-width: 138px;
    max-width: 210px;
    min-height: 40px;

    padding: 0 18px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    justify-self: end !important;
    align-self: flex-end !important;

    margin-top: 2px !important;

    border-radius: 8px;

    font-weight: 700;
}


/* Remove previous rule that pushed buttons to the bottom. */
.custody-action-card > .button {
    margin-top: 2px !important;
}


/* ------------------------------------------------------------
   Preparation card
------------------------------------------------------------ */

.custody-prepare-card {
    gap: 12px !important;
}

.custody-preparation-state {
    display: flex;
    align-items: flex-start;

    gap: 10px;

    padding: 12px 13px;

    border: 1px solid #d9e3ee;
    border-radius: 9px;

    background: #f8fafc;
}

.custody-preparation-state__icon {
    width: 25px;
    height: 25px;
    flex: 0 0 25px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: #e7f7ef;
    color: #16814b;

    font-size: 12px;
    font-weight: 800;
}

.custody-preparation-state__icon.is-ready {
    background: #eaf3ff;
    color: #1769e0;
}

.custody-preparation-state__icon.is-pending {
    background: #fff4d8;
    color: #9a6700;
}

.custody-preparation-state > div {
    min-width: 0;

    display: flex;
    flex-direction: column;

    gap: 3px;
}

.custody-preparation-state strong {
    color: #102a43;

    font-size: 13px;
    line-height: 1.3;
}

.custody-preparation-state span:not(.custody-preparation-state__icon) {
    color: #63788d;

    font-size: 12px;
    line-height: 1.4;
}


/* ------------------------------------------------------------
   Prepared quantities
------------------------------------------------------------ */

.custody-prepared-card {
    gap: 12px !important;
}

.custody-prepared-card .table-wrap {
    margin-bottom: 0;
}

.custody-prepared-card input[type="number"] {
    width: 110px !important;
    max-width: 110px !important;
    min-height: 38px !important;
}


/* ------------------------------------------------------------
   Physical release
------------------------------------------------------------ */

.custody-physical-release-card {
    gap: 13px;
}

.custody-physical-release-card textarea {
    height: 78px !important;
    min-height: 78px !important;
    max-height: 120px;

    resize: vertical;
}


/* ------------------------------------------------------------
   Mobile
------------------------------------------------------------ */

@media (max-width: 760px) {
    .custody-action-card > .button,
    .custody-compact-action,
    .custody-save-quantities,
    .custody-physical-release-card > .button {
        width: 100% !important;
        max-width: none !important;

        justify-self: stretch !important;
        align-self: stretch !important;
    }

    .custody-prepared-card input[type="number"] {
        width: 100% !important;
        max-width: none !important;
    }
}

/* ============================================================
   CUSTODY CONSOLIDATED UX V2
============================================================ */


/* ------------------------------------------------------------
   Pickup Schedule
------------------------------------------------------------ */

.custody-pickup-area {
    display: block !important;
}

.custody-pickup-card {
    display: grid !important;

    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr);

    gap: 14px 16px !important;

    width: 100%;
    height: auto !important;
    min-height: 0 !important;
}

.custody-pickup-card > .card-header {
    grid-column: 1 / -1;

    margin-bottom: 0;
}

.custody-pickup-card > .custody-compact-helper {
    grid-column: 1 / -1;

    margin: -1px 0 0;
}

.custody-pickup-card > .custody-save-schedule {
    grid-column: 1 / -1;

    justify-self: end !important;
    align-self: center !important;

    width: auto !important;
    min-width: 138px;
    max-width: 190px;

    margin-top: 0 !important;
}


/* ------------------------------------------------------------
   Hidden route-only Preparation form
------------------------------------------------------------ */

.custody-hidden-prepare-form {
    display: none !important;
}


/* ------------------------------------------------------------
   Inline preparation status
------------------------------------------------------------ */

.custody-preparation-inline {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 16px;

    padding: 11px 13px;

    border: 1px solid #d7e3ef;
    border-radius: 9px;

    background: #f8fafc;
}

.custody-preparation-inline__main {
    min-width: 0;

    display: flex;
    align-items: center;

    gap: 10px;
}

.custody-preparation-inline__icon {
    flex: 0 0 26px;

    width: 26px;
    height: 26px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 999px;

    font-size: 12px;
    font-weight: 800;
}

.custody-preparation-inline__icon.is-complete {
    background: #e6f6ed;
    color: #13824a;
}

.custody-preparation-inline__icon.is-ready {
    background: #eaf3ff;
    color: #1769e0;
}

.custody-preparation-inline__icon.is-pending {
    background: #fff4d8;
    color: #9a6700;
}

.custody-preparation-inline__copy {
    min-width: 0;

    display: flex;
    flex-direction: column;

    gap: 2px;
}

.custody-preparation-inline__copy strong {
    color: #102a43;

    font-size: 13px;
    line-height: 1.3;
}

.custody-preparation-inline__copy span {
    color: #63788d;

    font-size: 12px;
    line-height: 1.4;
}


/* ------------------------------------------------------------
   Prepared Quantities
------------------------------------------------------------ */

.custody-prepared-card {
    gap: 13px !important;
}

.custody-prepared-card .table-wrap {
    margin-top: 0;
    margin-bottom: 0;
}

.custody-prepared-card input[type="number"] {
    width: 112px !important;
    max-width: 112px !important;

    min-height: 38px !important;
}


/* ------------------------------------------------------------
   Action grouping
------------------------------------------------------------ */

.custody-quantity-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;

    flex-wrap: wrap;

    gap: 9px;

    width: 100%;
}

.custody-save-quantities,
.custody-confirm-preparation,
.custody-save-schedule,
.custody-mark-issued {
    width: auto !important;

    min-width: 138px;
    max-width: 210px;

    min-height: 40px;

    padding-left: 18px;
    padding-right: 18px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    margin-top: 0 !important;

    border-radius: 8px;
}


/*
 * Override old custody-action-card rule that forced
 * width:100% and margin-top:auto.
 */
.custody-action-card > .button {
    width: auto !important;
    margin-top: 0 !important;

    align-self: flex-end !important;
}


/* ------------------------------------------------------------
   Physical release
------------------------------------------------------------ */

.custody-physical-release-card {
    gap: 13px !important;
}

.custody-physical-release-card textarea {
    height: 78px !important;
    min-height: 78px !important;
    max-height: 120px;

    resize: vertical;
}

.custody-physical-release-card > .custody-mark-issued {
    justify-self: end !important;
    align-self: flex-end !important;
}


/* ------------------------------------------------------------
   Mobile
------------------------------------------------------------ */

@media (max-width: 760px) {

    .custody-pickup-card {
        grid-template-columns: 1fr;
    }

    .custody-pickup-card > .card-header,
    .custody-pickup-card > .custody-compact-helper,
    .custody-pickup-card > .custody-save-schedule {
        grid-column: 1;
    }

    .custody-preparation-inline {
        align-items: flex-start;

        flex-direction: column;

        gap: 10px;
    }

    .custody-preparation-inline > .status-badge {
        align-self: flex-start;
    }

    .custody-quantity-actions {
        flex-direction: column;

        align-items: stretch;
    }

    .custody-save-quantities,
    .custody-confirm-preparation,
    .custody-save-schedule,
    .custody-mark-issued {
        width: 100% !important;
        max-width: none !important;

        align-self: stretch !important;
        justify-self: stretch !important;
    }

    .custody-prepared-card input[type="number"] {
        width: 100% !important;
        max-width: none !important;
    }
}

/* ============================================================
   PICKUP SAVE SCHEDULE BOTTOM RIGHT
============================================================ */

.custody-pickup-card > .custody-save-schedule {
    grid-column: 1 / -1;

    justify-self: end !important;
    align-self: end !important;

    width: auto !important;
    min-width: 138px;
    max-width: 190px;

    margin-top: 0 !important;
    margin-left: auto !important;
}

@media (max-width: 760px) {
    .custody-pickup-card > .custody-save-schedule {
        width: 100% !important;
        max-width: none !important;

        justify-self: stretch !important;
        align-self: stretch !important;

        margin-left: 0 !important;
    }
}

/* ============================================================
   SAVE SCHEDULE TRUE BOTTOM RIGHT
============================================================ */

.custody-pickup-card .custody-save-schedule {
    grid-column: 1 / -1 !important;

    display: inline-flex !important;

    width: auto !important;
    min-width: 140px !important;
    max-width: 190px !important;

    justify-self: end !important;
    align-self: end !important;

    margin-top: 2px !important;
    margin-right: 0 !important;
    margin-left: auto !important;

    place-self: end !important;
}

@media (max-width: 760px) {
    .custody-pickup-card .custody-save-schedule {
        width: 100% !important;
        max-width: none !important;

        justify-self: stretch !important;
        align-self: stretch !important;

        margin-left: 0 !important;

        place-self: stretch !important;
    }
}

/* ============================================================
   SAVE SCHEDULE ACTION ROW FINAL
============================================================ */

.custody-pickup-card > .custody-save-schedule-row {
    grid-column: 1 / -1 !important;

    width: 100% !important;

    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;

    margin-top: 2px !important;
    padding: 0 !important;
}

.custody-pickup-card
.custody-save-schedule-row
.custody-save-schedule {
    flex: 0 0 auto !important;

    width: auto !important;
    min-width: 140px !important;
    max-width: 190px !important;

    margin: 0 !important;

    align-self: auto !important;
    justify-self: auto !important;
    place-self: auto !important;
}

@media (max-width: 760px) {
    .custody-pickup-card > .custody-save-schedule-row {
        display: block !important;
    }

    .custody-pickup-card
    .custody-save-schedule-row
    .custody-save-schedule {
        width: 100% !important;
        max-width: none !important;
    }
}

/* ============================================================
   SAVE SCHEDULE SOLID BLUE
============================================================ */

.custody-pickup-card
.custody-save-schedule-row
.custody-save-schedule {
    background: #1769e0 !important;
    border: 1px solid #1769e0 !important;
    color: #ffffff !important;

    box-shadow: 0 4px 10px rgba(23, 105, 224, 0.18);

    font-weight: 700;
}

.custody-pickup-card
.custody-save-schedule-row
.custody-save-schedule:hover:not(:disabled) {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
    color: #ffffff !important;

    box-shadow: 0 6px 14px rgba(23, 105, 224, 0.24);
}

.custody-pickup-card
.custody-save-schedule-row
.custody-save-schedule:active:not(:disabled) {
    background: #0b4fae !important;
    border-color: #0b4fae !important;
}

.custody-pickup-card
.custody-save-schedule-row
.custody-save-schedule:disabled {
    background: #dce6f2 !important;
    border-color: #dce6f2 !important;
    color: #7f8fa3 !important;

    box-shadow: none !important;
}

/* ============================================================
   BORROWER PREPARED QUANTITIES REVIEW UX
============================================================ */

.borrower-prepared-review {
    display: grid;
    gap: 14px;
}

.borrower-prepared-review__header {
    margin-bottom: 0;
}


/* ------------------------------------------------------------
   Summary
------------------------------------------------------------ */

.borrower-prepared-summary {
    display: grid;

    grid-template-columns:
        minmax(0, 1.25fr)
        minmax(0, 1.25fr)
        minmax(120px, .5fr);

    border: 1px solid #dce5ee;
    border-radius: 9px;

    overflow: hidden;

    background: #f8fafc;
}

.borrower-prepared-summary__item {
    display: flex;
    flex-direction: column;

    gap: 4px;

    padding: 11px 14px;

    border-right: 1px solid #e1e8ef;
}

.borrower-prepared-summary__item:last-child {
    border-right: 0;
}

.borrower-prepared-summary__item span {
    color: #6d7f91;

    font-size: 10px;
    font-weight: 700;

    text-transform: uppercase;
    letter-spacing: .04em;
}

.borrower-prepared-summary__item strong {
    color: #102a43;

    font-size: 12.5px;
    line-height: 1.35;
}


/* ------------------------------------------------------------
   Prepared status
------------------------------------------------------------ */

.borrower-prepared-intro {
    display: flex;
    align-items: center;

    gap: 10px;

    padding: 11px 13px;

    border: 1px solid #d9e5f2;
    border-radius: 9px;

    background: #f6faff;
}

.borrower-prepared-intro__icon {
    flex: 0 0 26px;

    width: 26px;
    height: 26px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 999px;

    background: #e7f6ee;
    color: #14804a;

    font-size: 12px;
    font-weight: 800;
}

.borrower-prepared-intro > div {
    display: flex;
    flex-direction: column;

    gap: 2px;
}

.borrower-prepared-intro strong {
    color: #17324d;

    font-size: 13px;
}

.borrower-prepared-intro div > span {
    color: #667a8f;

    font-size: 12px;
}


/* ------------------------------------------------------------
   Table
------------------------------------------------------------ */

.borrower-prepared-table {
    margin: 0;
}

.borrower-prepared-table table {
    table-layout: auto;
}

.borrower-prepared-table th,
.borrower-prepared-table td {
    padding-top: 10px;
    padding-bottom: 10px;
}

.borrower-prepared-table th:nth-child(2),
.borrower-prepared-table td:nth-child(2) {
    width: 150px;
}

.borrower-prepared-table th:nth-child(3),
.borrower-prepared-table td:nth-child(3),
.borrower-prepared-table th:nth-child(4),
.borrower-prepared-table td:nth-child(4) {
    width: 125px;
    text-align: center;
}

.borrower-prepared-qty {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 46px;
    min-height: 30px;

    padding: 3px 10px;

    border-radius: 7px;

    background: #edf5ff;
    color: #145fc8;

    font-size: 13px;
}


/* ------------------------------------------------------------
   Footer / confirmation action
------------------------------------------------------------ */

.borrower-prepared-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 18px;
}

.borrower-prepared-note {
    flex: 1;

    max-width: 720px;

    margin: 0;

    color: #687b8e;

    font-size: 12px;
    line-height: 1.5;
}

.borrower-confirm-prepared {
    flex: 0 0 auto;

    width: auto !important;
    min-width: 205px;
    max-width: 260px;

    min-height: 40px;

    padding-left: 18px;
    padding-right: 18px;

    background: #1769e0 !important;
    border-color: #1769e0 !important;
    color: #fff !important;
}

.borrower-confirm-prepared:hover {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
}


/* ------------------------------------------------------------
   Responsive
------------------------------------------------------------ */

@media (max-width: 760px) {
    .borrower-prepared-summary {
        grid-template-columns: 1fr;
    }

    .borrower-prepared-summary__item {
        border-right: 0;
        border-bottom: 1px solid #e1e8ef;
    }

    .borrower-prepared-summary__item:last-child {
        border-bottom: 0;
    }

    .borrower-prepared-footer {
        flex-direction: column;

        align-items: stretch;
    }

    .borrower-confirm-prepared {
        width: 100% !important;
        max-width: none;
    }
}

/* ============================================================
   BORROWER CONFIRMATION WAITING NOTICE
============================================================ */

.custody-borrower-confirmation-notice {
    display: flex;
    align-items: flex-start;

    gap: 11px;

    padding: 13px 15px;

    border: 1px solid #efcf86;
    border-left: 4px solid #d99a16;
    border-radius: 9px;

    background: #fffaf0;
}

.custody-borrower-confirmation-notice__icon {
    flex: 0 0 26px;

    width: 26px;
    height: 26px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 999px;

    background: #fff0c7;
    color: #946200;

    font-size: 13px;
    font-weight: 800;
}

.custody-borrower-confirmation-notice__content {
    min-width: 0;

    display: flex;
    flex-direction: column;

    gap: 3px;
}

.custody-borrower-confirmation-notice__content strong {
    color: #744d00;

    font-size: 13px;
    line-height: 1.35;
}

.custody-borrower-confirmation-notice__content p {
    margin: 0;

    color: #334155;

    font-size: 12.5px;
    line-height: 1.5;
}

.custody-borrower-confirmation-notice__content small {
    color: #718096;

    font-size: 11.5px;
    line-height: 1.45;
}

@media (max-width: 760px) {
    .custody-borrower-confirmation-notice {
        padding: 12px;
    }
}

/* ============================================================
   PREPARED QUANTITIES CONFIRMED NOTICE
============================================================ */

.custody-prepared-confirmed-notice {
    display: flex;
    align-items: flex-start;
    gap: 12px;

    margin-top: 18px;
    padding: 14px 16px;

    border: 1px solid #b7e3c9;
    border-left: 4px solid #2f9e5b;
    border-radius: 10px;

    background: #f3fbf6;
}

.custody-prepared-confirmed-notice__icon {
    flex: 0 0 28px;
    width: 28px;
    height: 28px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 999px;

    background: #dff5e7;
    color: #1f7a43;

    font-size: 14px;
    font-weight: 800;
    line-height: 1;
}

.custody-prepared-confirmed-notice__content {
    min-width: 0;
    flex: 1 1 auto;
}

.custody-prepared-confirmed-notice__content strong {
    display: block;
    margin: 0 0 4px;

    color: #14532d;
    font-size: 14px;
    font-weight: 800;
    line-height: 1.35;
}

.custody-prepared-confirmed-notice__content p {
    margin: 0;

    color: #334155;
    font-size: 13px;
    line-height: 1.5;
}

.custody-prepared-confirmed-notice__content small {
    display: block;
    margin-top: 4px;

    color: #64748b;
    font-size: 11.5px;
    line-height: 1.45;
}

.custody-prepared-confirmed-notice__badge {
    flex: 0 0 auto;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 4px 10px;

    border: 1px solid #9ed5b3;
    border-radius: 999px;

    background: #eaf8ef;
    color: #166534;

    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    white-space: nowrap;
}

@media (max-width: 760px) {
    .custody-prepared-confirmed-notice {
        flex-wrap: wrap;
    }

    .custody-prepared-confirmed-notice__badge {
        margin-left: 40px;
    }
}

/* ============================================================
   PHYSICAL RELEASE BORROWER CONFIRMATION NOTICE
============================================================ */

.custody-physical-borrower-confirmed-notice {
    display: flex;
    align-items: center;

    gap: 10px;

    padding: 11px 13px;

    border: 1px solid #b9e1c8;
    border-left: 4px solid #23935b;
    border-radius: 9px;

    background: #f2fbf6;
}

.custody-physical-borrower-confirmed-notice__icon {
    flex: 0 0 27px;

    width: 27px;
    height: 27px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 999px;

    background: #daf3e4;
    color: #167347;

    font-size: 13px;
    font-weight: 800;
}

.custody-physical-borrower-confirmed-notice__content {
    min-width: 0;

    display: flex;
    flex-direction: column;

    gap: 2px;
}

.custody-physical-borrower-confirmed-notice__content strong {
    color: #14532d;

    font-size: 13px;
    font-weight: 800;
    line-height: 1.35;
}

.custody-physical-borrower-confirmed-notice__content span {
    color: #526577;

    font-size: 12px;
    line-height: 1.45;
}

@media (max-width: 760px) {
    .custody-physical-borrower-confirmed-notice {
        align-items: flex-start;
    }
}

/* ============================================================
   PHYSICAL CONFIRMATION NOTICE ABOVE HANDOVER
============================================================ */

.custody-physical-borrower-confirmed-notice {
    margin-bottom: 14px !important;
}

/* ============================================================
   CUSTODY PROFESSIONAL RETURN UX 20260822
============================================================ */


/* ------------------------------------------------------------
   General custody card rhythm
------------------------------------------------------------ */

.content-area > .card {
    border-radius: 10px;
}

.content-area > .card > .card-header {
    margin-bottom: 0;
}

.content-area > .card > .card-header h2 {
    letter-spacing: -.01em;
}


/* ------------------------------------------------------------
   Issued record
------------------------------------------------------------ */

.content-area > .card:has(h2:is(
    :where(:not(*))
)) {
    min-height: 0;
}

/*
 * Keep informational copy compact.
 */
.content-area > .card p.meta {
    margin-top: 6px;
    margin-bottom: 0;
}


/* ------------------------------------------------------------
   Return inspection table
------------------------------------------------------------ */

.content-area .table-wrap {
    border-radius: 9px;
}

.content-area table th {
    vertical-align: middle;
}

.content-area table td {
    vertical-align: middle;
}

.content-area table input[type="number"],
.content-area table select,
.content-area table input[type="text"] {
    min-height: 42px;
}

.content-area table input[type="number"] {
    width: 104px;
    max-width: 104px;
}


/* ------------------------------------------------------------
   Return remarks
------------------------------------------------------------ */

.content-area textarea[name="remarks"],
.content-area textarea[name*="remark"] {
    min-height: 82px !important;
    height: 82px;
    max-height: 150px;
}


/* ------------------------------------------------------------
   Primary custody actions
------------------------------------------------------------ */

.custody-physical-release-card > .button,
.custody-save-quantities,
.custody-confirm-preparation {
    width: auto !important;
}


/*
 * Return action should not span the whole card.
 */
.content-area form button[type="submit"].button {
    min-height: 40px;
}


/* ------------------------------------------------------------
   Early return checkbox
------------------------------------------------------------ */

.content-area label.checkbox {
    align-items: center;
}


/* ------------------------------------------------------------
   Empty return history
------------------------------------------------------------ */

.content-area table tbody td[colspan] {
    padding-top: 16px;
    padding-bottom: 16px;

    color: #6b7d90;
}


/* ------------------------------------------------------------
   Native number spinners removed
------------------------------------------------------------ */

.content-area input[type="number"]::-webkit-inner-spin-button,
.content-area input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.content-area input[type="number"] {
    appearance: textfield;
    -moz-appearance: textfield;
}


/* ------------------------------------------------------------
   Mobile
------------------------------------------------------------ */

@media (max-width: 760px) {
    .content-area table input[type="number"] {
        width: 100%;
        max-width: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const normalizeMessage = (value) =>
        (value || '')
            .replace(/\s+/g, ' ')
            .trim();

    const successCallout =
        document.querySelector('.callout.success');

    if (!successCallout) {
        return;
    }

    const successText =
        normalizeMessage(successCallout.textContent);

    if (!successText) {
        return;
    }

    const possibleDuplicates =
        document.querySelectorAll(
            '.callout:not(.success), .notice, .alert, .banner'
        );

    possibleDuplicates.forEach((element) => {
        if (
            normalizeMessage(element.textContent) ===
            successText
        ) {
            element.hidden = true;
        }
    });
});
</script>

<script>
/* ============================================================
   CUSTODY RETURN WHOLE NUMBER UX 20260822
============================================================ */

document.addEventListener('DOMContentLoaded', () => {
    document
        .querySelectorAll('input[type="number"]')
        .forEach((input) => {
            input.step = '1';
            input.inputMode = 'numeric';

            const normalize = () => {
                if (input.value === '') {
                    return;
                }

                const numeric = Number(input.value);

                if (!Number.isFinite(numeric)) {
                    input.value = '';
                    return;
                }

                let value = Math.floor(numeric);

                if (input.hasAttribute('min')) {
                    const min = Number(input.min);

                    if (Number.isFinite(min)) {
                        value = Math.max(
                            value,
                            Math.ceil(min)
                        );
                    }
                }

                if (input.hasAttribute('max')) {
                    const max = Number(input.max);

                    if (Number.isFinite(max)) {
                        value = Math.min(
                            value,
                            Math.floor(max)
                        );
                    }
                }

                input.value = value;
            };

            input.addEventListener(
                'keydown',
                (event) => {
                    if (
                        [
                            '.',
                            ',',
                            'e',
                            'E',
                            '+',
                            '-',
                            'ArrowUp',
                            'ArrowDown'
                        ].includes(event.key)
                    ) {
                        event.preventDefault();
                    }
                }
            );

            input.addEventListener(
                'wheel',
                (event) => {
                    if (
                        document.activeElement ===
                        input
                    ) {
                        event.preventDefault();
                        input.blur();
                    }
                },
                {
                    passive: false
                }
            );

            input.addEventListener(
                'change',
                normalize
            );

            input.addEventListener(
                'blur',
                normalize
            );

            normalize();
        });
});
</script>

<style>
/* ============================================================
   RETURN INSPECTION CONDITIONAL FINDINGS UX 20260822
============================================================ */

/*
 * Once JS enhances the Return Inspection table,
 * Evidence + Reference are removed from the primary row.
 */
.return-inspection-enhanced thead th:nth-child(5),
.return-inspection-enhanced thead th:nth-child(6),
.return-inspection-enhanced tbody tr.return-inspection-item-row > td:nth-child(5),
.return-inspection-enhanced tbody tr.return-inspection-item-row > td:nth-child(6) {
    display: none !important;
}


/* Main table becomes easier to scan */
.return-inspection-enhanced thead th:nth-child(1) {
    width: 34%;
}

.return-inspection-enhanced thead th:nth-child(2) {
    width: 15%;
}

.return-inspection-enhanced thead th:nth-child(3) {
    width: 19%;
}

.return-inspection-enhanced thead th:nth-child(4) {
    width: 32%;
}

.return-inspection-enhanced
tbody
tr.return-inspection-item-row
> td {
    vertical-align: middle;
}


/* Whole-number quantity */
.return-inspection-enhanced input[type="number"] {
    width: 110px !important;
    max-width: 110px !important;
}


/* ------------------------------------------------------------
   Conditional finding detail row
------------------------------------------------------------ */

.return-finding-detail-row {
    display: none;
}

.return-finding-detail-row.is-visible {
    display: table-row;
}

.return-finding-detail-row > td {
    padding: 0 14px 14px !important;

    border-top: 0 !important;

    background: #ffffff;
}

.return-finding-panel {
    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        minmax(220px, .72fr);

    gap: 14px;

    padding: 13px 14px;

    border: 1px solid #ead39b;
    border-left: 4px solid #d59a1d;
    border-radius: 9px;

    background: #fffaf0;
}

.return-finding-panel__heading {
    grid-column: 1 / -1;

    display: flex;
    align-items: center;
    gap: 8px;

    margin-bottom: -2px;
}

.return-finding-panel__icon {
    flex: 0 0 24px;

    width: 24px;
    height: 24px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 999px;

    background: #fff0c7;
    color: #936000;

    font-size: 12px;
    font-weight: 800;
}

.return-finding-panel__heading strong {
    color: #754d00;

    font-size: 12.5px;
}

.return-finding-field {
    display: flex;
    flex-direction: column;

    gap: 6px;
}

.return-finding-field > span {
    color: #526579;

    font-size: 11px;
    font-weight: 800;

    text-transform: uppercase;
    letter-spacing: .035em;
}

.return-finding-field input[type="file"],
.return-finding-field input[type="text"] {
    width: 100% !important;
    max-width: none !important;

    min-height: 40px;
}

.return-finding-field small {
    color: #7a8796;

    font-size: 11px;
    line-height: 1.4;
}


/* Visually identify affected source row */
.return-inspection-item-row.has-return-finding {
    background: #fffdf7;
}

.return-inspection-item-row.has-return-finding > td:first-child {
    box-shadow: inset 3px 0 0 #d59a1d;
}


/* ------------------------------------------------------------
   Return Remarks
------------------------------------------------------------ */

.return-inspection-form textarea {
    min-height: 82px !important;
    height: 82px !important;
    max-height: 140px !important;
}


/* ------------------------------------------------------------
   Record Physical Return action
------------------------------------------------------------ */

.return-inspection-action-row {
    display: flex;

    justify-content: flex-end;
    align-items: center;

    width: 100%;

    margin-top: 2px;
}

.return-inspection-action-row .return-record-button {
    width: auto !important;

    min-width: 180px;
    max-width: 230px;

    min-height: 40px;

    padding-left: 20px;
    padding-right: 20px;

    background: #1769e0 !important;
    border-color: #1769e0 !important;
    color: #ffffff !important;

    font-weight: 700;
}

.return-inspection-action-row
.return-record-button:hover:not(:disabled) {
    background: #0f5bc7 !important;
    border-color: #0f5bc7 !important;
}


/* Native number spinner removal */
.return-inspection-enhanced
input[type="number"]::-webkit-inner-spin-button,
.return-inspection-enhanced
input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.return-inspection-enhanced input[type="number"] {
    appearance: textfield;
    -moz-appearance: textfield;
}


/* ------------------------------------------------------------
   Mobile
------------------------------------------------------------ */

@media (max-width: 760px) {

    .return-finding-panel {
        grid-template-columns: 1fr;
    }

    .return-finding-panel__heading {
        grid-column: 1;
    }

    .return-inspection-enhanced input[type="number"] {
        width: 100% !important;
        max-width: none !important;
    }

    .return-inspection-action-row {
        display: block;
    }

    .return-inspection-action-row .return-record-button {
        width: 100% !important;
        max-width: none !important;
    }
}

/* ============================================================
   RETURN ISSUE DETAILS PROFESSIONAL UX 20260822
============================================================ */

/*
 * Keep the affected item row almost neutral.
 * The detail panel below carries the warning state.
 */
.return-inspection-item-row.has-return-finding {
    background: #ffffff !important;
}

.return-inspection-item-row.has-return-finding > td:first-child {
    box-shadow: none !important;
}


/* ------------------------------------------------------------
   Detail row
------------------------------------------------------------ */

.return-finding-detail-row > td {
    padding: 0 12px 11px !important;

    border-top: 0 !important;

    background: #ffffff !important;
}


/* ------------------------------------------------------------
   Compact issue panel
------------------------------------------------------------ */

.return-finding-panel {
    display: grid !important;

    grid-template-columns:
        minmax(0, 1.15fr)
        minmax(220px, .85fr) !important;

    gap: 10px 14px !important;

    padding: 11px 12px !important;

    border: 1px solid #d9e1ea !important;
    border-left: 3px solid #d89a1d !important;
    border-radius: 7px !important;

    background: #f8fafc !important;

    box-shadow: none !important;
}


/* ------------------------------------------------------------
   Heading
------------------------------------------------------------ */

.return-finding-panel__heading {
    grid-column: 1 / -1 !important;

    display: flex !important;
    align-items: center !important;

    gap: 7px !important;

    margin: 0 !important;
}

.return-finding-panel__icon {
    flex: 0 0 20px !important;

    width: 20px !important;
    height: 20px !important;

    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;

    border-radius: 999px !important;

    background: #fff1cc !important;
    color: #946200 !important;

    font-size: 10px !important;
    font-weight: 800 !important;
}

.return-finding-panel__heading strong {
    color: #27384a !important;

    font-size: 12px !important;
    font-weight: 800 !important;

    line-height: 1.3 !important;
}


/* ------------------------------------------------------------
   Fields
------------------------------------------------------------ */

.return-finding-field {
    display: flex !important;
    flex-direction: column !important;

    gap: 5px !important;
}

.return-finding-field > span {
    color: #44566c !important;

    font-size: 11px !important;
    font-weight: 700 !important;

    letter-spacing: 0 !important;
    text-transform: none !important;
}

.return-finding-field small {
    display: none !important;
}


/* ------------------------------------------------------------
   File upload
------------------------------------------------------------ */

.return-finding-field input[type="file"] {
    width: 100% !important;

    min-height: 38px !important;

    padding: 3px !important;

    border: 1px solid #c7d3df !important;
    border-radius: 7px !important;

    background: #ffffff !important;
    color: #526579 !important;

    font-size: 12px !important;
}

.return-finding-field
input[type="file"]::file-selector-button {
    min-height: 30px;

    margin-right: 9px;

    padding: 5px 11px;

    border: 1px solid #b9c8d8;
    border-radius: 6px;

    background: #ffffff;
    color: #17324d;

    font-size: 11.5px;
    font-weight: 700;

    cursor: pointer;
}

.return-finding-field
input[type="file"]::file-selector-button:hover {
    background: #f2f6fa;
}


/* ------------------------------------------------------------
   Reference
------------------------------------------------------------ */

.return-finding-field input[type="text"] {
    width: 100% !important;

    min-height: 38px !important;

    padding: 8px 10px !important;

    border: 1px solid #c7d3df !important;
    border-radius: 7px !important;

    background: #ffffff !important;

    font-size: 12px !important;
}


/* ------------------------------------------------------------
   Responsive
------------------------------------------------------------ */

@media (max-width: 760px) {
    .return-finding-panel {
        grid-template-columns: 1fr !important;
    }

    .return-finding-panel__heading {
        grid-column: 1 !important;
    }
}
</style>


<script>
document.addEventListener('DOMContentLoaded', () => {

    /* ==========================================================
       Locate Return Inspection without depending on route names
    ========================================================== */

    const headings =
        [...document.querySelectorAll('h1, h2, h3')];

    const returnHeading =
        headings.find((heading) =>
            heading.textContent
                .replace(/\s+/g, ' ')
                .trim()
                .toLowerCase() ===
            'return inspection'
        );

    if (!returnHeading) {
        return;
    }

    const card =
        returnHeading.closest('.card');

    if (!card) {
        return;
    }

    const form =
        card.closest('form') ||
        card.querySelector('form') ||
        returnHeading.closest('form');

    const table =
        card.querySelector('table');

    if (!table) {
        return;
    }

    table.classList.add(
        'return-inspection-enhanced'
    );

    if (form) {
        form.classList.add(
            'return-inspection-form'
        );
    }


    /* ==========================================================
       Normal-condition recognition

       We use the displayed option label so this remains compatible
       with the current backend values.
    ========================================================== */

    const isNormalCondition = (select) => {
        if (!select) {
            return true;
        }

        const selected =
            select.options[
                select.selectedIndex
            ];

        const label =
            (selected?.textContent || '')
                .replace(/\s+/g, ' ')
                .trim()
                .toLowerCase();

        return (
            label === 'fine / good' ||
            label === 'fine/good' ||
            label === 'good' ||
            label === 'serviceable'
        );
    };


    /* ==========================================================
       Enhance each physical-return item
    ========================================================== */

    const rows =
        [...table.querySelectorAll('tbody > tr')]
            .filter((row) =>
                row.querySelector('select') &&
                row.querySelector('input[type="number"]')
            );

    rows.forEach((row) => {

        row.classList.add(
            'return-inspection-item-row'
        );

        const cells =
            [...row.children];

        /*
         * Current table:
         * 1 Item
         * 2 Outstanding
         * 3 Returned Qty
         * 4 Condition/Finding
         * 5 Evidence
         * 6 Reference
         */
        if (cells.length < 6) {
            return;
        }

        const conditionSelect =
            cells[3].querySelector('select');

        const evidenceInput =
            cells[4].querySelector(
                'input[type="file"]'
            );

        const referenceInput =
            cells[5].querySelector(
                'input:not([type="hidden"])'
            );

        if (!conditionSelect) {
            return;
        }


        /* ------------------------------------------------------
           Build expandable finding row
        ------------------------------------------------------ */

        const detailRow =
            document.createElement('tr');

        detailRow.className =
            'return-finding-detail-row';

        const detailCell =
            document.createElement('td');

        detailCell.colSpan = 4;

        const panel =
            document.createElement('div');

        panel.className =
            'return-finding-panel';


        /* Heading */
        const panelHeading =
            document.createElement('div');

        panelHeading.className =
            'return-finding-panel__heading';

        const icon =
            document.createElement('span');

        icon.className =
            'return-finding-panel__icon';

        icon.setAttribute(
            'aria-hidden',
            'true'
        );

        icon.textContent = '!';

        const headingText =
            document.createElement('strong');

        headingText.textContent =
            'Finding details';

        panelHeading.appendChild(icon);
        panelHeading.appendChild(headingText);

        panel.appendChild(panelHeading);


        /* Evidence */
        if (evidenceInput) {

            const evidenceField =
                document.createElement('label');

            evidenceField.className =
                'return-finding-field';

            const evidenceLabel =
                document.createElement('span');

            evidenceLabel.textContent =
                'Evidence';

            const evidenceHelp =
                document.createElement('small');

            evidenceHelp.textContent =
                'Optional photo or supporting document.';

            evidenceField.appendChild(
                evidenceLabel
            );

            /*
             * Move the real form field, do not clone it.
             * This preserves its original name and backend binding.
             */
            evidenceField.appendChild(
                evidenceInput
            );

            evidenceField.appendChild(
                evidenceHelp
            );

            panel.appendChild(
                evidenceField
            );
        }


        /* Reference */
        if (referenceInput) {

            const referenceField =
                document.createElement('label');

            referenceField.className =
                'return-finding-field';

            const referenceLabel =
                document.createElement('span');

            referenceLabel.textContent =
                'Reference';

            const referenceHelp =
                document.createElement('small');

            referenceHelp.textContent =
                'Optional incident or accountability reference.';

            referenceField.appendChild(
                referenceLabel
            );

            referenceField.appendChild(
                referenceInput
            );

            referenceField.appendChild(
                referenceHelp
            );

            panel.appendChild(
                referenceField
            );
        }

        detailCell.appendChild(panel);
        detailRow.appendChild(detailCell);

        row.insertAdjacentElement(
            'afterend',
            detailRow
        );


        /* ------------------------------------------------------
           Toggle based on Condition / Finding
        ------------------------------------------------------ */

        const updateFindingState = () => {

            const normal =
                isNormalCondition(
                    conditionSelect
                );

            row.classList.toggle(
                'has-return-finding',
                !normal
            );

            detailRow.classList.toggle(
                'is-visible',
                !normal
            );

            if (evidenceInput) {
                evidenceInput.disabled =
                    normal;

                if (normal) {
                    evidenceInput.value = '';
                }
            }

            if (referenceInput) {
                referenceInput.disabled =
                    normal;

                if (normal) {
                    referenceInput.value = '';
                }
            }
        };

        conditionSelect.addEventListener(
            'change',
            updateFindingState
        );

        updateFindingState();
    });


    /* ==========================================================
       Compact Record Physical Return button
    ========================================================== */

    if (form) {

        const buttons =
            [...form.querySelectorAll(
                'button[type="submit"], input[type="submit"]'
            )];

        const recordButton =
            buttons.find((button) =>
                (button.textContent || button.value || '')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase() ===
                'record physical return'
            );

        if (
            recordButton &&
            !recordButton.closest(
                '.return-inspection-action-row'
            )
        ) {
            recordButton.classList.add(
                'return-record-button'
            );

            const actionRow =
                document.createElement('div');

            actionRow.className =
                'return-inspection-action-row';

            recordButton.parentNode.insertBefore(
                actionRow,
                recordButton
            );

            actionRow.appendChild(
                recordButton
            );
        }
    }


    /* ==========================================================
       Whole-number Return Qty
    ========================================================== */

    table
        .querySelectorAll(
            'input[type="number"]'
        )
        .forEach((input) => {

            input.step = '1';
            input.inputMode = 'numeric';

            input.addEventListener(
                'keydown',
                (event) => {
                    if (
                        [
                            '.',
                            ',',
                            'e',
                            'E',
                            '+',
                            '-',
                            'ArrowUp',
                            'ArrowDown'
                        ].includes(event.key)
                    ) {
                        event.preventDefault();
                    }
                }
            );

            input.addEventListener(
                'wheel',
                (event) => {
                    if (
                        document.activeElement ===
                        input
                    ) {
                        event.preventDefault();
                        input.blur();
                    }
                },
                {
                    passive: false
                }
            );

            const normalize = () => {
                if (input.value === '') {
                    return;
                }

                const number =
                    Number(input.value);

                if (!Number.isFinite(number)) {
                    input.value = '0';
                    return;
                }

                let value =
                    Math.max(
                        0,
                        Math.floor(number)
                    );

                if (input.hasAttribute('max')) {
                    const max =
                        Number(input.max);

                    if (Number.isFinite(max)) {
                        value =
                            Math.min(
                                value,
                                Math.floor(max)
                            );
                    }
                }

                input.value = value;
            };

            input.addEventListener(
                'change',
                normalize
            );

            input.addEventListener(
                'blur',
                normalize
            );
        });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {

    document
        .querySelectorAll(
            '.return-finding-panel'
        )
        .forEach((panel) => {

            const heading =
                panel.querySelector(
                    '.return-finding-panel__heading strong'
                );

            if (heading) {
                heading.textContent =
                    'Issue details';
            }

            const fields =
                panel.querySelectorAll(
                    '.return-finding-field'
                );

            fields.forEach((field) => {

                const label =
                    field.querySelector(
                        ':scope > span'
                    );

                if (!label) {
                    return;
                }

                const current =
                    label.textContent
                        .trim()
                        .toLowerCase();

                if (current === 'evidence') {
                    label.textContent =
                        'Evidence (optional)';
                }

                if (current === 'reference') {
                    label.textContent =
                        'Reference (optional)';

                    const input =
                        field.querySelector(
                            'input[type="text"]'
                        );

                    if (input) {
                        input.placeholder =
                            'Incident or accountability reference';
                    }
                }
            });
        });
});
</script>

<style>
/* ============================================================
   BORROWER SLIP ACCOMPLISHED UX 20260822
============================================================ */

.borrower-slip-accomplished {
    grid-column: 1 / -1;

    margin-top: 11px;
    padding-top: 11px;

    border-top: 1px solid #e2e8f0;
}

.borrower-slip-accomplished__header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    margin-bottom: 9px;
}

.borrower-slip-accomplished__header > div {
    display: flex;
    flex-direction: column;

    gap: 2px;
}

.borrower-slip-accomplished__header strong {
    color: #17324d;

    font-size: 12.5px;
    font-weight: 800;
}

.borrower-slip-accomplished__header small {
    color: #718096;

    font-size: 11px;
}

.borrower-slip-upload-form,
.borrower-slip-replace-form {
    display: flex;

    align-items: flex-end;
    justify-content: flex-end;

    gap: 9px;

    margin: 0;
}

.borrower-slip-accomplished__actions {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;

    gap: 12px;
}

.borrower-slip-file-control {
    min-width: 0;
    flex: 1 1 auto;

    display: flex;
    flex-direction: column;

    gap: 5px;

    margin: 0;
}

.borrower-slip-file-control > span {
    color: #526579;

    font-size: 11px;
    font-weight: 700;
}

.borrower-slip-file-control input[type="file"] {
    width: 100%;

    min-height: 37px;

    padding: 3px;

    border: 1px solid #cbd6e2;
    border-radius: 7px;

    background: #ffffff;

    color: #526579;

    font-size: 11.5px;
}

.borrower-slip-file-control
input[type="file"]::file-selector-button {
    min-height: 29px;

    margin-right: 8px;

    padding: 5px 10px;

    border: 1px solid #b9c8d8;
    border-radius: 6px;

    background: #f7f9fc;
    color: #17324d;

    font-size: 11px;
    font-weight: 700;

    cursor: pointer;
}

.borrower-slip-file-control
input[type="file"]::file-selector-button:hover {
    background: #eef3f8;
}

.borrower-slip-upload-form > .button,
.borrower-slip-replace-form > .button {
    flex: 0 0 auto;

    width: auto !important;
    min-width: 145px;
}

@media (max-width: 760px) {

    .borrower-slip-accomplished__actions,
    .borrower-slip-upload-form,
    .borrower-slip-replace-form {
        flex-direction: column;
        align-items: stretch;
    }

    .borrower-slip-upload-form > .button,
    .borrower-slip-replace-form > .button,
    .borrower-slip-accomplished__actions > .button {
        width: 100% !important;
    }
}

/* ============================================================
   BORROWER SLIP COMPACT PROFESSIONAL UX 20260822
============================================================ */

/*
 * Accomplished copy becomes a compact secondary control,
 * not another large section inside the document card.
 */
.borrower-slip-accomplished {
    grid-column: 1 / -1 !important;

    margin-top: 10px !important;
    padding: 10px 11px !important;

    border: 1px solid #dbe4ed !important;
    border-radius: 8px !important;

    background: #ffffff !important;
}


/* remove old divider styling */
.borrower-slip-accomplished {
    border-top: 1px solid #dbe4ed !important;
}


/* ------------------------------------------------------------
   Compact heading
------------------------------------------------------------ */

.borrower-slip-accomplished__header {
    min-height: 24px;

    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;

    gap: 10px !important;

    margin: 0 0 8px !important;
}

.borrower-slip-accomplished__header > div {
    display: block !important;
}

.borrower-slip-accomplished__header strong {
    color: #18344f !important;

    font-size: 12px !important;
    font-weight: 800 !important;

    line-height: 1.3 !important;
}


/* hide redundant subtitle if old markup remains */
.borrower-slip-accomplished__header small {
    display: none !important;
}


/* ------------------------------------------------------------
   Upload status
------------------------------------------------------------ */

.borrower-slip-scan-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-height: 22px;

    padding: 3px 8px;

    border-radius: 999px;

    font-size: 10px;
    font-weight: 800;
    line-height: 1;

    white-space: nowrap;
}

.borrower-slip-scan-badge.is-pending {
    border: 1px solid #d6dee8;

    background: #f3f6f9;
    color: #64748b;
}

.borrower-slip-scan-badge.is-uploaded {
    border: 1px solid #a9dbc0;

    background: #eaf8f0;
    color: #167347;
}


/* ------------------------------------------------------------
   Upload bar
------------------------------------------------------------ */

.borrower-slip-upload-form,
.borrower-slip-replace-form {
    display: grid !important;

    grid-template-columns:
        minmax(0, 1fr)
        auto;

    align-items: center !important;

    gap: 8px !important;

    margin: 0 !important;
}


/* Remove unnecessary label spacing */
.borrower-slip-file-control {
    min-width: 0 !important;

    display: block !important;

    margin: 0 !important;
}

.borrower-slip-file-control > span {
    display: none !important;
}


/* ------------------------------------------------------------
   File input
------------------------------------------------------------ */

.borrower-slip-file-control input[type="file"] {
    width: 100% !important;
    height: 38px !important;
    min-height: 38px !important;

    padding: 3px 5px !important;

    border: 1px solid #c8d4e0 !important;
    border-radius: 7px !important;

    background: #f8fafc !important;
    color: #607085 !important;

    font-size: 11.5px !important;

    box-shadow: none !important;
}

.borrower-slip-file-control
input[type="file"]:focus {
    outline: none !important;

    border-color: #1769e0 !important;

    box-shadow:
        0 0 0 3px
        rgba(23, 105, 224, .10) !important;
}

.borrower-slip-file-control
input[type="file"]::file-selector-button {
    height: 30px !important;

    margin: 0 9px 0 0 !important;

    padding: 4px 10px !important;

    border: 1px solid #bdcad8 !important;
    border-radius: 5px !important;

    background: #ffffff !important;
    color: #17324d !important;

    font-size: 11px !important;
    font-weight: 700 !important;

    cursor: pointer;
}

.borrower-slip-file-control
input[type="file"]::file-selector-button:hover {
    background: #f1f5f9 !important;
}


/* ------------------------------------------------------------
   Upload / Replace buttons
------------------------------------------------------------ */

.borrower-slip-upload-form > .button,
.borrower-slip-replace-form > .button {
    width: auto !important;
    min-width: 112px !important;

    min-height: 38px !important;

    margin: 0 !important;
    padding: 7px 13px !important;

    border-color: #1769e0 !important;

    background: #1769e0 !important;
    color: #ffffff !important;

    font-size: 11.5px !important;
    font-weight: 800 !important;

    white-space: nowrap;
}

.borrower-slip-upload-form > .button:hover,
.borrower-slip-replace-form > .button:hover {
    border-color: #1258bf !important;

    background: #1258bf !important;
}


/* ------------------------------------------------------------
   Uploaded state
------------------------------------------------------------ */

.borrower-slip-accomplished__actions {
    display: grid !important;

    grid-template-columns:
        auto
        minmax(0, 1fr);

    align-items: center !important;

    gap: 9px !important;
}

.borrower-slip-accomplished__actions > .button {
    width: auto !important;

    min-height: 38px !important;

    margin: 0 !important;

    white-space: nowrap;
}

.borrower-slip-replace-form {
    width: 100%;
}


/* ------------------------------------------------------------
   Operational form card
------------------------------------------------------------ */

/*
 * Keep the main Borrower Slip card as the primary document card.
 * The upload box is visually subordinate.
 */
.operational-form-card:has(
    .borrower-slip-accomplished
) {
    align-items: flex-start !important;
}


/* ------------------------------------------------------------
   Mobile
------------------------------------------------------------ */

@media (max-width: 760px) {

    .borrower-slip-upload-form,
    .borrower-slip-replace-form,
    .borrower-slip-accomplished__actions {
        grid-template-columns: 1fr !important;
    }

    .borrower-slip-upload-form > .button,
    .borrower-slip-replace-form > .button,
    .borrower-slip-accomplished__actions > .button {
        width: 100% !important;
    }
}

/* ============================================================
   CUSTODY ENTERPRISE UI SYSTEM 20260822
   Final visual hierarchy / consistency layer
============================================================ */


/* ============================================================
   1. PAGE RHYTHM
============================================================ */

.content-area {
    margin-bottom: 16px;
}

.content-grid {
    gap: 16px;
}

.content-grid.two {
    align-items: start;
}


/* ============================================================
   2. PRIMARY CARDS
============================================================ */

.content-area > .card,
.content-grid > .card,
.content-grid > form.card {
    border: 1px solid #d7e1eb !important;
    border-radius: 10px !important;

    background: #ffffff !important;

    box-shadow:
        0 1px 2px rgba(15, 35, 55, .035) !important;
}


/*
 * Prevent cards from feeling unnecessarily tall.
 */
.content-area > .card,
.content-grid > .card,
.content-grid > form.card {
    min-height: 0 !important;
}


/* ============================================================
   3. CARD HEADERS
============================================================ */

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 14px;

    min-height: 54px;

    margin: 0 !important;
    padding: 12px 16px !important;

    border-bottom: 1px solid #dce4ec !important;

    background: #fbfcfd !important;
}

.card-header > div {
    min-width: 0;
}

.card-header .eyebrow,
.card-header p.eyebrow {
    margin: 0 0 4px !important;

    color: #61758b !important;

    font-size: 9.5px !important;
    font-weight: 800 !important;

    line-height: 1.2;

    letter-spacing: .075em !important;
    text-transform: uppercase;
}

.card-header h2 {
    margin: 0 !important;

    color: #092b50 !important;

    font-size: 17px !important;
    font-weight: 750 !important;

    line-height: 1.25 !important;

    letter-spacing: -.015em;
}


/* ============================================================
   4. STATUS BADGES
============================================================ */

.status-badge,
.operational-form-badge,
.borrower-slip-scan-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-height: 22px;

    padding: 3px 8px !important;

    border-radius: 999px !important;

    font-size: 9.5px !important;
    font-weight: 800 !important;

    line-height: 1 !important;

    white-space: nowrap;
}

.operational-form-badge.is-ready,
.borrower-slip-scan-badge.is-uploaded {
    border: 1px solid #b9dfc9 !important;

    background: #edf9f2 !important;
    color: #167347 !important;
}

.operational-form-badge.is-pending,
.borrower-slip-scan-badge.is-pending {
    border: 1px solid #d8e0e8 !important;

    background: #f4f6f8 !important;
    color: #68798b !important;
}


/* ============================================================
   5. BUTTON SYSTEM
============================================================ */

.button {
    border-radius: 7px !important;

    font-weight: 750 !important;

    box-shadow: none !important;
}

.button.primary {
    border-color: #1769e0 !important;

    background: #1769e0 !important;
    color: #ffffff !important;
}

.button.primary:hover:not(:disabled) {
    border-color: #1259c0 !important;

    background: #1259c0 !important;
}

.button.secondary {
    border-color: #b9c9d9 !important;

    background: #ffffff !important;
    color: #174b79 !important;
}

.button.secondary:hover:not(:disabled) {
    background: #f5f8fb !important;
}


/*
 * Desktop actions stay compact.
 */
.content-area .button,
.content-grid .button {
    width: auto;
}

.button.small {
    min-height: 34px !important;

    padding: 6px 11px !important;

    font-size: 10.5px !important;
}


/* ============================================================
   6. RELEASE SUMMARY / DEFINITION LIST
============================================================ */

.card dl {
    margin: 0;
}

.card dl > div {
    min-height: 38px;

    display: grid;

    grid-template-columns:
        minmax(110px, .75fr)
        minmax(0, 1.65fr);

    align-items: center;

    gap: 14px;

    padding: 8px 0;

    border-bottom: 1px solid #e3e9ef;
}

.card dl > div:last-child {
    border-bottom: 0;
}

.card dt {
    color: #536b83;

    font-size: 11px;
    font-weight: 700;
}

.card dd {
    margin: 0;

    color: #102f4c;

    font-size: 12.5px;
    font-weight: 500;
}


/* ============================================================
   7. OPERATIONAL FORMS CONTAINER
============================================================ */

.operational-form-list {
    display: grid !important;

    gap: 9px !important;
}

.operational-form-card,
.operational-form-card.is-ready,
.operational-form-card.is-pending {
    display: grid !important;

    grid-template-columns:
        36px
        minmax(0, 1fr)
        auto;

    align-items: center !important;

    gap: 12px !important;

    padding: 11px 12px !important;

    border: 1px solid #d7e2eb !important;
    border-radius: 9px !important;

    background: #ffffff !important;

    box-shadow: none !important;
}


/*
 * Do not tint the entire card green.
 * Status is communicated by icon + badge.
 */
.operational-form-card.is-ready {
    border-color: #cfdce7 !important;

    background: #ffffff !important;
}


/* ============================================================
   8. OPERATIONAL FORM ICONS
============================================================ */

.operational-form-card__icon {
    grid-column: 1;

    width: 34px !important;
    height: 34px !important;

    min-width: 34px !important;

    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;

    margin: 0 !important;

    border-radius: 999px !important;

    font-size: 14px !important;
    font-weight: 800 !important;
}

.operational-form-card.is-ready
.operational-form-card__icon {
    border: 0 !important;

    background: #238d62 !important;
    color: #ffffff !important;
}


/* ============================================================
   9. OPERATIONAL FORM CONTENT
============================================================ */

.operational-form-card__content {
    grid-column: 2;

    min-width: 0;
}

.operational-form-card__heading {
    display: flex !important;
    align-items: center !important;

    gap: 7px !important;

    margin: 0 !important;
}

.operational-form-card__heading strong {
    color: #0d3050 !important;

    font-size: 12.5px !important;
    font-weight: 800 !important;

    line-height: 1.3;
}

.operational-form-card__content > p {
    margin: 3px 0 0 !important;

    color: #607287 !important;

    font-size: 11px !important;
    line-height: 1.4 !important;
}


/*
 * "Final" below every form is redundant when Ready already exists.
 */
.operational-form-card__content > small {
    display: none !important;
}


/* ============================================================
   10. VIEW / PRINT ACTION
============================================================ */

.operational-form-action {
    grid-column: 3;

    min-width: 98px !important;
    min-height: 35px !important;

    margin: 0 !important;

    padding: 6px 12px !important;

    font-size: 10.5px !important;

    white-space: nowrap;
}


/* ============================================================
   11. ACCOMPLISHED BORROWER SLIP
============================================================ */

/*
 * Treat accomplished copy as a secondary document-state row,
 * not a nested card.
 */
.borrower-slip-accomplished {
    grid-column: 2 / 4 !important;

    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        auto;

    align-items: center;

    gap: 8px 12px;

    margin: 8px 0 0 !important;
    padding: 9px 0 0 !important;

    border: 0 !important;
    border-top: 1px solid #e4eaf0 !important;
    border-radius: 0 !important;

    background: transparent !important;
}


/* ============================================================
   12. ACCOMPLISHED COPY HEADER
============================================================ */

.borrower-slip-accomplished__header {
    grid-column: 1;

    display: flex !important;
    align-items: center !important;

    gap: 8px !important;

    margin: 0 !important;

    min-height: 26px !important;
}

.borrower-slip-accomplished__header > div {
    display: block !important;
}

.borrower-slip-accomplished__header strong {
    color: #29445d !important;

    font-size: 11px !important;
    font-weight: 750 !important;

    line-height: 1.3 !important;
}

.borrower-slip-accomplished__header small {
    display: none !important;
}

.borrower-slip-accomplished__header
.borrower-slip-scan-badge {
    margin-left: 2px;
}


/* ============================================================
   13. UPLOADED STATE TOOLBAR
============================================================ */

.borrower-slip-accomplished__toolbar {
    grid-column: 2;

    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;

    gap: 6px !important;

    width: auto !important;

    margin: 0 !important;
}

.borrower-slip-view-scan,
.borrower-slip-replace-trigger {
    min-height: 32px !important;

    padding: 5px 10px !important;

    border-radius: 6px !important;

    font-size: 10px !important;

    white-space: nowrap;
}


/* ============================================================
   14. REPLACE SCAN DISCLOSURE
============================================================ */

.borrower-slip-replace-disclosure {
    margin: 0 !important;
}

.borrower-slip-replace-disclosure > summary {
    list-style: none;

    cursor: pointer;
}

.borrower-slip-replace-disclosure > summary::-webkit-details-marker {
    display: none;
}

.borrower-slip-replace-disclosure[open] {
    grid-column: 1 / -1;

    width: 100%;

    margin-top: 7px !important;
}

.borrower-slip-replace-disclosure[open]
.borrower-slip-replace-trigger {
    margin-left: auto !important;
    margin-bottom: 7px !important;
}


/* ============================================================
   15. FIRST UPLOAD / REPLACE FORM
============================================================ */

.borrower-slip-upload-form,
.borrower-slip-replace-form {
    grid-column: 1 / -1;

    display: grid !important;

    grid-template-columns:
        minmax(0, 1fr)
        auto;

    align-items: center !important;

    gap: 7px !important;

    width: 100%;

    margin: 0 !important;
}

.borrower-slip-file-control {
    display: block !important;

    min-width: 0;

    margin: 0 !important;
}

.borrower-slip-file-control > span {
    display: none !important;
}


/* ============================================================
   16. FILE INPUT
============================================================ */

.borrower-slip-file-control input[type="file"] {
    width: 100% !important;

    height: 34px !important;
    min-height: 34px !important;

    padding: 2px 4px !important;

    border: 1px solid #c9d4df !important;
    border-radius: 6px !important;

    background: #f8fafc !important;
    color: #627489 !important;

    font-size: 10px !important;

    box-shadow: none !important;
}

.borrower-slip-file-control
input[type="file"]::file-selector-button {
    height: 28px !important;

    margin-right: 7px !important;

    padding: 4px 9px !important;

    border: 1px solid #c0ccd8 !important;
    border-radius: 5px !important;

    background: #ffffff !important;
    color: #173d61 !important;

    font-size: 10px !important;
    font-weight: 700 !important;

    cursor: pointer;
}

.borrower-slip-file-control
input[type="file"]::file-selector-button:hover {
    background: #f2f6f9 !important;
}


/* ============================================================
   17. UPLOAD / REPLACE SUBMIT
============================================================ */

.borrower-slip-upload-form > .button,
.borrower-slip-replace-form > .button {
    min-width: 116px !important;
    min-height: 34px !important;

    margin: 0 !important;

    padding: 5px 11px !important;

    font-size: 10px !important;

    white-space: nowrap;
}


/* ============================================================
   18. TABLE SYSTEM
============================================================ */

.table-wrap {
    overflow: hidden;

    border: 1px solid #d7e1eb !important;
    border-radius: 9px !important;

    background: #ffffff;
}

.table-wrap table {
    width: 100%;

    border-collapse: collapse;
}

.table-wrap thead th {
    padding: 10px 12px !important;

    border-bottom: 1px solid #bfcddd !important;

    background: #eef3f7 !important;
    color: #183b5b !important;

    font-size: 9.5px !important;
    font-weight: 800 !important;

    line-height: 1.25;

    letter-spacing: .045em;
    text-transform: uppercase;
}

.table-wrap tbody td {
    padding: 11px 12px !important;

    border-bottom: 1px solid #e1e8ef !important;

    background: #ffffff;

    font-size: 12px;
    line-height: 1.4;

    vertical-align: middle;
}

.table-wrap tbody tr:last-child td {
    border-bottom: 0 !important;
}

.table-wrap tbody tr:hover td {
    background: #fbfcfe;
}


/* ============================================================
   19. TABLE ITEM TYPOGRAPHY
============================================================ */

.table-wrap td strong {
    color: #0c2e4b;

    font-size: 12.5px;
    font-weight: 800;
}

.table-wrap td small {
    color: #73849a;

    font-size: 10.5px;
}


/* ============================================================
   20. FORM CONTROLS
============================================================ */

.content-area input[type="text"],
.content-area input[type="number"],
.content-area input[type="datetime-local"],
.content-area input[type="date"],
.content-area select,
.content-area textarea,
.content-grid input[type="text"],
.content-grid input[type="number"],
.content-grid input[type="datetime-local"],
.content-grid input[type="date"],
.content-grid select,
.content-grid textarea {
    border: 1px solid #bdccda !important;
    border-radius: 7px !important;

    background: #ffffff !important;
    color: #102f4c !important;

    box-shadow: none !important;
}

.content-area input:focus,
.content-area select:focus,
.content-area textarea:focus,
.content-grid input:focus,
.content-grid select:focus,
.content-grid textarea:focus {
    outline: 0 !important;

    border-color: #1769e0 !important;

    box-shadow:
        0 0 0 3px
        rgba(23, 105, 224, .10) !important;
}


/* ============================================================
   21. TEXTAREA
============================================================ */

.content-area textarea,
.content-grid textarea {
    min-height: 78px !important;

    padding: 10px 11px !important;

    resize: vertical;
}


/* ============================================================
   22. LABELS
============================================================ */

.content-area label,
.content-grid label {
    color: #29445e;

    font-size: 11px;
    font-weight: 700;
}

.content-area label small,
.content-grid label small,
.content-area .meta,
.content-grid .meta {
    color: #718399 !important;

    font-size: 10.5px !important;
    font-weight: 400 !important;

    line-height: 1.4;
}


/* ============================================================
   23. NOTICE SYSTEM
============================================================ */

.callout,
.custody-borrower-confirmation-notice,
.custody-prepared-confirmed-notice,
.custody-physical-borrower-confirmed-notice {
    border-radius: 8px !important;

    box-shadow: none !important;
}

.callout {
    padding: 11px 13px !important;

    font-size: 12px !important;
    line-height: 1.45 !important;
}

.callout p {
    margin: 3px 0 0 !important;
}


/* Success */
.callout.success {
    border: 1px solid #b4dfc6 !important;
    border-left: 3px solid #238d62 !important;

    background: #f1faf5 !important;
    color: #226442 !important;
}


/* Info */
.callout.info {
    border: 1px solid #c6d9ed !important;
    border-left: 3px solid #2473ba !important;

    background: #f3f8fc !important;
    color: #244c70 !important;
}


/* Warning */
.callout.warning,
.callout.warn {
    border: 1px solid #ead29a !important;
    border-left: 3px solid #d29a24 !important;

    background: #fffaf0 !important;
    color: #76560d !important;
}


/* ============================================================
   24. RETURN INSPECTION
============================================================ */

.return-inspection-enhanced
tbody
tr.return-inspection-item-row
> td {
    background: #ffffff !important;
}

.return-inspection-item-row.has-return-finding {
    background: #ffffff !important;
}

.return-finding-detail-row > td {
    padding: 0 12px 10px !important;

    background: #ffffff !important;
}

.return-finding-panel {
    padding: 10px 11px !important;

    border: 1px solid #d7e1eb !important;
    border-left: 3px solid #d09a2c !important;
    border-radius: 7px !important;

    background: #fafbfd !important;

    box-shadow: none !important;
}

.return-finding-panel__heading {
    margin-bottom: 1px !important;
}

.return-finding-panel__heading strong {
    color: #263f56 !important;

    font-size: 11.5px !important;
}

.return-finding-field > span {
    color: #53677b !important;

    font-size: 10.5px !important;
}


/* ============================================================
   25. RETURN ACTION AREA
============================================================ */

.return-inspection-action-row {
    display: flex !important;

    justify-content: flex-end !important;

    margin-top: 8px !important;
}

.return-inspection-action-row .return-record-button {
    width: auto !important;

    min-width: 168px !important;
    max-width: none !important;

    min-height: 38px !important;
}


/* ============================================================
   26. CHECKBOX ROWS
============================================================ */

label.checkbox {
    display: flex;
    align-items: flex-start;

    gap: 8px;

    margin: 0;

    font-size: 11px !important;
    line-height: 1.45;
}

label.checkbox input[type="checkbox"] {
    flex: 0 0 auto;

    margin-top: 2px;
}


/* ============================================================
   27. EMPTY STATES
============================================================ */

.table-wrap td[colspan] {
    padding: 18px 14px !important;

    color: #718399 !important;

    font-size: 11.5px !important;

    text-align: left;
}


/* ============================================================
   28. MOBILE
============================================================ */

@media (max-width: 760px) {

    .content-area {
        margin-bottom: 12px;
    }

    .card-header {
        align-items: flex-start;

        padding: 11px 12px !important;
    }

    .card-header h2 {
        font-size: 15px !important;
    }


    /* Operational forms */
    .operational-form-card,
    .operational-form-card.is-ready,
    .operational-form-card.is-pending {
        grid-template-columns:
            34px
            minmax(0, 1fr);

        gap: 9px 10px !important;
    }

    .operational-form-action {
        grid-column: 2;

        justify-self: stretch;

        width: 100% !important;
    }

    .borrower-slip-accomplished {
        grid-column: 1 / -1 !important;

        grid-template-columns: 1fr !important;
    }

    .borrower-slip-accomplished__header,
    .borrower-slip-accomplished__toolbar {
        grid-column: 1 !important;
    }

    .borrower-slip-accomplished__toolbar {
        justify-content: stretch !important;
    }

    .borrower-slip-view-scan,
    .borrower-slip-replace-trigger {
        flex: 1 1 auto;

        text-align: center;
    }

    .borrower-slip-upload-form,
    .borrower-slip-replace-form {
        grid-template-columns: 1fr !important;
    }

    .borrower-slip-upload-form > .button,
    .borrower-slip-replace-form > .button {
        width: 100% !important;
    }


    /* Summary */
    .card dl > div {
        grid-template-columns: 1fr;

        gap: 3px;
    }


    /* Buttons */
    .return-inspection-action-row .return-record-button {
        width: 100% !important;
    }


    /* Tables keep horizontal scroll where needed */
    .table-wrap {
        overflow-x: auto;
    }
}
</style>


<style>
/* CUSTODY PROFESSIONAL WORKSPACE UI V3 20260822 */

body.custody-ui-v3 {
    --c-navy: #0b2854;
    --c-blue: #1769e0;
    --c-blue-soft: #eef5ff;
    --c-border: #d7e1ec;
    --c-border-soft: #e8eef5;
    --c-muted: #667990;
}

body.custody-ui-v3 .page-heading {
    align-items: flex-end;
    margin-bottom: 16px;
}

body.custody-ui-v3 .page-heading h1 {
    margin: 3px 0 4px;
}

body.custody-ui-v3 .content-grid.two {
    grid-template-columns: minmax(300px, .82fr) minmax(440px, 1.18fr);
    align-items: start;
    gap: 16px;
}

body.custody-ui-v3 .content-area {
    margin-top: 16px;
}

body.custody-ui-v3 .card {
    border: 1px solid var(--c-border);
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(11, 40, 84, .035);
}

body.custody-ui-v3 .card-header {
    min-height: auto;
    margin: 0;
    padding: 0 0 12px;
    border-bottom: 1px solid var(--c-border-soft);
}

body.custody-ui-v3 .card-header h2 {
    margin-top: 2px;
    color: var(--c-navy);
    font-size: 17px;
    line-height: 1.25;
}

/* Release summary */

body.custody-ui-v3 .custody-summary-card .detail-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0;
    margin-top: 2px;
}

.custody-summary-fact {
    min-width: 0;
    padding: 11px 12px;
    border-bottom: 1px solid var(--c-border-soft);
}

.custody-summary-fact:nth-child(odd) {
    border-right: 1px solid var(--c-border-soft);
}

.custody-summary-fact:nth-last-child(-n + 2) {
    border-bottom: 0;
}

.custody-summary-fact dt {
    margin: 0 0 4px;
    color: #6b7d92;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .045em;
    text-transform: uppercase;
}

.custody-summary-fact dd {
    margin: 0;
    color: #17324d;
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.4;
}

.custody-summary-fact.is-outstanding {
    background: #f4f8ff;
}

.custody-summary-fact.is-outstanding dd {
    color: var(--c-blue);
    font-size: 15px;
}

/* Operational forms */

body.custody-ui-v3 .custody-forms-card .operational-form-list {
    gap: 9px;
}

body.custody-ui-v3 .custody-forms-card .operational-form-card {
    grid-template-columns: 34px minmax(0, 1fr) auto;
    gap: 11px;
    padding: 12px;
    border-radius: 10px;
    box-shadow: none;
}

body.custody-ui-v3 .operational-form-card__icon {
    width: 32px;
    height: 32px;
}

body.custody-ui-v3 .operational-form-card__heading {
    gap: 6px;
    margin-bottom: 2px;
}

body.custody-ui-v3 .operational-form-card__heading strong {
    font-size: 13px;
}

body.custody-ui-v3 .operational-form-card__content p {
    font-size: 11.5px;
}

body.custody-ui-v3 .operational-form-action {
    min-width: 94px;
    min-height: 36px;
    padding: 7px 12px;
}

body.custody-ui-v3 .borrower-slip-accomplished {
    grid-column: 2 / -1;
    display: grid;
    grid-template-columns: minmax(120px, .55fr) minmax(0, 1.45fr);
    align-items: center;
    gap: 10px;
    margin-top: 7px;
    padding-top: 10px;
}

body.custody-ui-v3 .borrower-slip-accomplished__header {
    align-items: flex-start;
    margin: 0;
}

body.custody-ui-v3 .borrower-slip-accomplished__actions,
body.custody-ui-v3 .borrower-slip-upload-form,
body.custody-ui-v3 .borrower-slip-replace-form {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 7px;
    width: auto;
}

.custody-file-picker {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 36px;
    padding: 7px 11px;
    overflow: hidden;
    border: 1px solid #b8c9dc;
    border-radius: 8px;
    background: #fff;
    color: #244363;
    cursor: pointer;
    font-size: 11px;
    font-weight: 750;
}

.custody-file-picker:hover {
    border-color: var(--c-blue);
    background: var(--c-blue-soft);
    color: var(--c-blue);
}

.custody-file-picker input[type="file"] {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}

.custody-selected-file {
    display: block;
    max-width: 145px;
    overflow: hidden;
    color: #6c7d91;
    font-size: 10px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.custody-laundry-followup {
    margin: 2px 0 0 !important;
    padding: 11px 12px !important;
    border: 1px solid #ead293 !important;
    border-left: 4px solid #d49a1b !important;
    border-radius: 9px !important;
    background: #fffaf0 !important;
}

.custody-laundry-followup strong {
    display: block;
    margin-bottom: 3px;
    color: #754d00;
    font-size: 12px;
}

.custody-laundry-followup p {
    margin: 0;
    color: #865f13;
    font-size: 11.5px;
    line-height: 1.45;
}

.custody-laundry-evidence {
    margin-top: 2px;
    padding: 11px 12px;
    border: 1px solid #cce6d9;
    border-radius: 9px;
    background: #f3faf6;
}

/* Issued items */

body.custody-ui-v3 .custody-items-section .card,
body.custody-ui-v3 .custody-history-section .card {
    overflow: hidden;
}

body.custody-ui-v3 .custody-items-section .card-header,
body.custody-ui-v3 .custody-history-section .card-header {
    margin: 0 16px;
    padding-top: 14px;
}

body.custody-ui-v3 .custody-items-table th,
body.custody-ui-v3 .custody-items-table td,
body.custody-ui-v3 .custody-history-table th,
body.custody-ui-v3 .custody-history-table td {
    padding: 11px 13px;
}

body.custody-ui-v3 .custody-items-table th:nth-child(2),
body.custody-ui-v3 .custody-items-table td:nth-child(2),
body.custody-ui-v3 .custody-items-table th:nth-child(3),
body.custody-ui-v3 .custody-items-table td:nth-child(3) {
    display: none;
}

body.custody-ui-v3 .custody-items-table th:first-child {
    width: 42%;
}

.custody-condition-pill {
    display: inline-flex;
    align-items: center;
    min-height: 23px;
    padding: 3px 8px;
    border-radius: 999px;
    background: #edf7f2;
    color: #187453;
    font-size: 9.5px;
    font-weight: 800;
    text-transform: uppercase;
}

/* Return inspection */

body.custody-ui-v3 .custody-return-workspace {
    padding: 18px;
    gap: 0;
}

body.custody-ui-v3 .custody-return-workspace .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 14px;
}

.custody-fill-outstanding {
    min-height: 35px;
    padding: 7px 11px;
    border: 1px solid #b8c9dc;
    border-radius: 8px;
    background: #fff;
    color: #244363;
    cursor: pointer;
    font-size: 11px;
    font-weight: 750;
}

.custody-fill-outstanding:hover {
    border-color: var(--c-blue);
    background: var(--c-blue-soft);
    color: var(--c-blue);
}

body.custody-ui-v3 .custody-return-workspace .table-wrap {
    border: 1px solid var(--c-border);
    border-radius: 10px;
    overflow: hidden;
}

body.custody-ui-v3 .return-inspection-enhanced th,
body.custody-ui-v3 .return-inspection-enhanced td {
    padding: 11px 12px;
}

body.custody-ui-v3 .return-inspection-enhanced input[type="number"] {
    width: 100% !important;
    max-width: 110px !important;
    min-height: 38px;
    text-align: center;
}

body.custody-ui-v3 .return-inspection-enhanced select {
    min-height: 38px;
}

.custody-return-remarks {
    display: block;
    margin-top: 14px;
}

body.custody-ui-v3 .custody-return-remarks textarea {
    min-height: 72px !important;
    height: 72px !important;
    margin-top: 6px;
}

body.custody-ui-v3 .early-return-option {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: start;
    gap: 3px 9px;
    width: fit-content;
    margin: 12px 0 0;
    padding: 10px 12px;
    border: 1px solid var(--c-border);
    border-radius: 9px;
    background: #f8fafc;
}

body.custody-ui-v3 .early-return-option input {
    grid-row: 1 / 3;
    margin-top: 2px;
}

.early-return-option__help,
.return-action-guidance {
    color: #6e7f92;
    font-size: 10.5px;
    font-weight: 500;
    line-height: 1.4;
}

body.custody-ui-v3 .return-inspection-action-row {
    display: flex !important;
    align-items: center;
    justify-content: space-between !important;
    gap: 14px;
    margin-top: 14px !important;
    padding-top: 14px;
    border-top: 1px solid var(--c-border-soft);
}

.return-action-guidance {
    margin: 0;
}

body.custody-ui-v3 .return-inspection-action-row .return-record-button {
    width: auto !important;
    min-width: 190px !important;
    max-width: none !important;
    margin-left: auto;
}

/* Return history */

body.custody-ui-v3 .custody-history-section.is-empty thead {
    display: none;
}

.custody-history-empty {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 15px 2px;
    color: #6b7c90;
}

.custody-history-empty__icon {
    flex: 0 0 32px;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #eef4fb;
    color: #3b6084;
    font-weight: 800;
}

.custody-history-empty strong {
    display: block;
    margin-bottom: 2px;
    color: #294866;
    font-size: 12px;
}

.custody-history-empty p {
    margin: 0;
    font-size: 11px;
}

body.custody-ui-v3 .custody-early-return-section textarea {
    min-height: 80px;
}

body.custody-ui-v3 .custody-early-return-section button[type="submit"] {
    width: auto;
    min-width: 180px;
    justify-self: end;
}

@media (max-width: 960px) {
    body.custody-ui-v3 .content-grid.two {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    body.custody-ui-v3 .custody-summary-card .detail-list {
        grid-template-columns: 1fr;
    }

    .custody-summary-fact:nth-child(odd) {
        border-right: 0;
    }

    .custody-summary-fact:nth-last-child(-n + 2) {
        border-bottom: 1px solid var(--c-border-soft);
    }

    .custody-summary-fact:last-child {
        border-bottom: 0;
    }

    body.custody-ui-v3 .operational-form-card {
        grid-template-columns: 32px minmax(0, 1fr);
    }

    body.custody-ui-v3 .operational-form-action,
    body.custody-ui-v3 .borrower-slip-accomplished {
        grid-column: 1 / -1;
    }

    body.custody-ui-v3 .borrower-slip-accomplished {
        grid-template-columns: 1fr;
    }

    body.custody-ui-v3 .custody-return-workspace .card-header,
    body.custody-ui-v3 .return-inspection-action-row {
        align-items: stretch;
        flex-direction: column;
    }

    .custody-fill-outstanding,
    body.custody-ui-v3 .return-inspection-action-row .return-record-button {
        width: 100% !important;
    }
}
</style>

<script>
/* CUSTODY PROFESSIONAL WORKSPACE UI V3 20260822 */

document.addEventListener('DOMContentLoaded', () => {
    document.body.classList.add('custody-ui-v3');

    const normalize = (value) =>
        (value || '').replace(/\s+/g, ' ').trim().toLowerCase();

    const headings = [...document.querySelectorAll('h1, h2, h3, h4, h5, h6')];
    const heading = (label) => headings.find((item) => normalize(item.textContent) === normalize(label));
    const card = (label) => heading(label)?.closest('.card') || null;

    const summaryCard = card('Release Summary');

    if (summaryCard) {
        summaryCard.classList.add('custody-summary-card');
        const list = summaryCard.querySelector('.detail-list');

        if (list && !list.querySelector('.custody-summary-fact')) {
            [...list.querySelectorAll(':scope > dt')].forEach((term) => {
                const description = term.nextElementSibling;
                if (!description || description.tagName !== 'DD') return;

                const fact = document.createElement('div');
                fact.className = 'custody-summary-fact';
                if (normalize(term.textContent) === 'outstanding') fact.classList.add('is-outstanding');
                list.insertBefore(fact, term);
                fact.append(term, description);
            });
        }
    }

    const formsCard = card('Operational Forms');

    if (formsCard) {
        formsCard.classList.add('custody-forms-card');

        formsCard.querySelectorAll('.operational-form-card').forEach((formCard) => {
            const name = normalize(formCard.querySelector('.operational-form-card__heading strong')?.textContent);
            const badge = formCard.querySelector('.operational-form-badge');
            if (badge && normalize(badge.textContent) === 'ready' && ['borrower slip', 'laundry form'].includes(name)) {
                badge.textContent = 'Form Available';
            }
        });

        formsCard.querySelectorAll('.borrower-slip-file-control').forEach((label) => {
            label.classList.add('custody-file-picker');
            const input = label.querySelector('input[type="file"]');
            let pickerText = label.querySelector('span');

            if (!pickerText) {
                pickerText = document.createElement('span');
                pickerText.textContent = 'Choose Scan';
                label.prepend(pickerText);
            }

            if (input && !label.parentElement.querySelector('.custody-selected-file')) {
                const selected = document.createElement('small');
                selected.className = 'custody-selected-file';
                selected.textContent = 'No scan selected';
                label.insertAdjacentElement('afterend', selected);
                input.addEventListener('change', () => {
                    selected.textContent = input.files?.[0]?.name || 'No scan selected';
                });
            }
        });
    }

    const laundryCard = card('Laundry Return Status');

    if (laundryCard) {
        const laundrySection = laundryCard.closest('section');
        const target = formsCard?.querySelector('.operational-form-list');

        if (target) {
            const warnings = [...laundryCard.querySelectorAll('.callout.warning')];
            const pending = warnings.find((item) => normalize(item.textContent).includes('laundry form pending'));
            const rejection = warnings.find((item) => normalize(item.textContent).includes('replacement scan requested'));
            const evidence = laundryCard.querySelector('.evidence-row');

            if (pending) {
                pending.classList.add('custody-laundry-followup');
                const title = pending.querySelector('strong');
                if (title) title.textContent = 'Accomplished Laundry Form required';
                target.appendChild(pending);
            }

            if (evidence) {
                evidence.classList.add('custody-laundry-evidence');
                target.appendChild(evidence);
            }

            if (rejection) {
                rejection.classList.add('custody-laundry-followup');
                target.appendChild(rejection);
            }
        }

        laundrySection?.remove();
    }

    const itemsCard = card('Items');

    if (itemsCard) {
        const section = itemsCard.closest('section');
        const table = itemsCard.querySelector('table');
        section?.classList.add('custody-items-section');
        table?.classList.add('custody-items-table');

        table?.querySelectorAll('tbody tr').forEach((row) => {
            const cell = row.cells[6];
            if (!cell || cell.querySelector('.custody-condition-pill')) return;
            const value = cell.textContent.trim() || 'Not recorded';
            cell.textContent = '';
            const pill = document.createElement('span');
            pill.className = 'custody-condition-pill';
            pill.textContent = value;
            cell.appendChild(pill);
        });
    }

    const returnCard = card('Return Inspection');

    if (returnCard) {
        const section = returnCard.closest('section');
        const form = returnCard.matches('form') ? returnCard : returnCard.closest('form');
        section?.classList.add('custody-return-section');
        form?.classList.add('custody-return-workspace');

        const returnHeader = returnCard.querySelector('.card-header');

        if (returnHeader && !returnHeader.querySelector('.custody-fill-outstanding')) {
            const fill = document.createElement('button');
            fill.type = 'button';
            fill.className = 'custody-fill-outstanding';
            fill.textContent = 'Return all outstanding';
            fill.addEventListener('click', () => {
                returnCard.querySelectorAll('tbody tr.return-inspection-item-row').forEach((row) => {
                    const input = row.querySelector('input[type="number"]');
                    const number = Number((row.cells[1]?.textContent || '').replace(/[^0-9.-]/g, ''));
                    if (input && Number.isFinite(number)) {
                        input.value = Math.max(0, Math.floor(number));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });
            returnHeader.appendChild(fill);
        }

        form?.querySelector('textarea[name="remarks"]')?.closest('label')?.classList.add('custody-return-remarks');

        const early = form?.querySelector('input[name="early_return"]');
        const earlyLabel = early?.closest('label');

        if (earlyLabel) {
            earlyLabel.classList.add('early-return-option');
            if (!earlyLabel.querySelector('.early-return-option__help')) {
                const help = document.createElement('small');
                help.className = 'early-return-option__help';
                help.textContent = 'Use only when items are physically returned before the approved return date.';
                earlyLabel.appendChild(help);
            }
        }

        const recordButton = [...(form?.querySelectorAll('button[type="submit"], button:not([type])') || [])]
            .find((button) => normalize(button.textContent) === 'record physical return');

        if (recordButton) {
            recordButton.classList.add('return-record-button');
            let row = recordButton.closest('.return-inspection-action-row');

            if (!row) {
                row = document.createElement('div');
                row.className = 'return-inspection-action-row';
                recordButton.parentNode.insertBefore(row, recordButton);
                row.appendChild(recordButton);
            }

            if (!row.querySelector('.return-action-guidance')) {
                const guidance = document.createElement('p');
                guidance.className = 'return-action-guidance';
                guidance.textContent = 'Verify quantities and item conditions before recording.';
                row.prepend(guidance);
            }
        }
    }

    const historyCard = card('Return History');

    if (historyCard) {
        const section = historyCard.closest('section');
        const table = historyCard.querySelector('table');
        section?.classList.add('custody-history-section');
        table?.classList.add('custody-history-table');

        const empty = [...historyCard.querySelectorAll('tbody td')]
            .find((cell) => normalize(cell.textContent) === 'no returns recorded yet.');

        if (empty) {
            section?.classList.add('is-empty');
            empty.innerHTML = '<div class="custody-history-empty"><span class="custody-history-empty__icon" aria-hidden="true">&#8634;</span><div><strong>No physical returns recorded</strong><p>Completed return transactions will appear here.</p></div></div>';
        }

        const earlyCard = card('Early Return Notice');
        const earlySection = earlyCard?.closest('section');
        if (earlySection && section && earlySection !== section) {
            earlySection.insertAdjacentElement('afterend', section);
        }
    }

    card('Early Return Notice')?.closest('section')?.classList.add('custody-early-return-section');
});
</script>

<style>
/* CUSTODY UI V3.1 COMPACT SUMMARY AND FORMS FIX 20260822 */

/* Never force the two top cards to equal height. */
body.custody-ui-v3 .content-grid.two {
    align-items: start !important;
}

body.custody-ui-v3 .content-grid.two > .card {
    align-self: start !important;
    height: auto !important;
    min-height: 0 !important;
}

/* Release Summary: two clean stacked facts per row. */
body.custody-ui-v3 .custody-summary-card .detail-list {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    grid-auto-flow: row !important;
    align-items: stretch !important;
    margin: 0 !important;
    padding: 0 !important;
}

body.custody-ui-v3 .custody-summary-card .custody-summary-fact {
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    justify-content: center !important;
    gap: 4px !important;
    min-width: 0 !important;
    min-height: 66px !important;
    margin: 0 !important;
    padding: 10px 14px !important;
}

body.custody-ui-v3 .custody-summary-card .custody-summary-fact dt,
body.custody-ui-v3 .custody-summary-card .custody-summary-fact dd {
    display: block !important;
    grid-column: auto !important;
    grid-row: auto !important;
    width: 100% !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    border: 0 !important;
}

body.custody-ui-v3 .custody-summary-card .custody-summary-fact dt {
    color: #6a7c91 !important;
    font-size: 9.5px !important;
    line-height: 1.25 !important;
}

body.custody-ui-v3 .custody-summary-card .custody-summary-fact dd {
    color: #17324d !important;
    font-size: 12px !important;
    line-height: 1.35 !important;
    overflow-wrap: normal !important;
    word-break: normal !important;
}

body.custody-ui-v3 .custody-summary-card .custody-summary-fact.is-outstanding dd {
    color: #1769e0 !important;
    font-size: 14px !important;
}

/* Operational Forms: remove inherited full-width form behavior. */
body.custody-ui-v3 .custody-forms-card .operational-form-card {
    height: auto !important;
    min-height: 0 !important;
}

body.custody-ui-v3 .custody-forms-card .borrower-slip-accomplished {
    grid-column: 1 / -1 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: nowrap !important;
    gap: 12px !important;
    min-height: 0 !important;
    margin: 8px 0 0 !important;
    padding: 10px 0 0 !important;
}

body.custody-ui-v3 .borrower-slip-accomplished__header {
    flex: 0 0 auto !important;
    display: flex !important;
    align-items: center !important;
    flex-direction: row !important;
    gap: 7px !important;
    min-width: 0 !important;
    margin: 0 !important;
}

body.custody-ui-v3 .borrower-slip-accomplished__header > div {
    display: block !important;
}

body.custody-ui-v3 .borrower-slip-accomplished__header strong {
    white-space: nowrap;
}

body.custody-ui-v3 .borrower-slip-accomplished__actions {
    flex: 1 1 auto !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    flex-wrap: nowrap !important;
    gap: 7px !important;
    min-width: 0 !important;
    width: auto !important;
}

body.custody-ui-v3 .borrower-slip-replace-form,
body.custody-ui-v3 .borrower-slip-upload-form {
    flex: 0 1 auto !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 7px !important;
    min-width: 0 !important;
    width: auto !important;
    margin: 0 !important;
    padding: 0 !important;
}

body.custody-ui-v3 .custody-file-picker {
    flex: 0 0 auto !important;
    display: inline-flex !important;
    width: auto !important;
    min-width: 108px !important;
    max-width: 135px !important;
    margin: 0 !important;
    white-space: nowrap;
}

body.custody-ui-v3 .custody-file-picker > span {
    display: inline !important;
    color: inherit !important;
    font-size: 11px !important;
    line-height: 1 !important;
}

body.custody-ui-v3 .custody-selected-file {
    display: none !important;
    flex: 0 1 100px !important;
    max-width: 100px !important;
    margin: 0 !important;
}

body.custody-ui-v3 .custody-selected-file.has-file {
    display: block !important;
}

body.custody-ui-v3 .borrower-slip-accomplished__actions > .button,
body.custody-ui-v3 .borrower-slip-replace-form > .button,
body.custody-ui-v3 .borrower-slip-upload-form > .button {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 92px !important;
    max-width: none !important;
    min-height: 36px !important;
    margin: 0 !important;
    padding: 7px 11px !important;
    white-space: nowrap;
}

/* Merge the Laundry reminder into the Laundry Form row. */
body.custody-ui-v3 .operational-form-card .custody-laundry-followup {
    grid-column: 2 / -1 !important;
    display: flex !important;
    align-items: baseline !important;
    flex-wrap: wrap !important;
    gap: 3px 7px !important;
    margin: 7px 0 0 !important;
    padding: 8px 10px !important;
}

body.custody-ui-v3 .operational-form-card .custody-laundry-followup strong,
body.custody-ui-v3 .operational-form-card .custody-laundry-followup p {
    margin: 0 !important;
}

@media (max-width: 820px) {
    body.custody-ui-v3 .custody-forms-card .borrower-slip-accomplished,
    body.custody-ui-v3 .borrower-slip-accomplished__actions {
        align-items: flex-start !important;
        flex-direction: column !important;
    }

    body.custody-ui-v3 .borrower-slip-replace-form,
    body.custody-ui-v3 .borrower-slip-upload-form {
        justify-content: flex-start !important;
        flex-wrap: wrap !important;
    }
}

@media (max-width: 540px) {
    body.custody-ui-v3 .custody-summary-card .detail-list {
        grid-template-columns: 1fr !important;
    }

    body.custody-ui-v3 .custody-summary-card .custody-summary-fact {
        min-height: 58px !important;
        border-right: 0 !important;
        border-bottom: 1px solid var(--c-border-soft) !important;
    }
}
</style>

<script>
/* CUSTODY UI V3.1 COMPACT SUMMARY AND FORMS FIX 20260822 */

document.addEventListener('DOMContentLoaded', () => {
    const normalize = (value) =>
        (value || '').replace(/\s+/g, ' ').trim().toLowerCase();

    const formCards = [
        ...document.querySelectorAll('.operational-form-card')
    ];

    const laundryCard = formCards.find((card) =>
        normalize(
            card.querySelector('.operational-form-card__heading strong')?.textContent
        ) === 'laundry form'
    );

    const followup = document.querySelector('.custody-laundry-followup');

    if (laundryCard && followup && !laundryCard.contains(followup)) {
        laundryCard.appendChild(followup);
    }

    document.querySelectorAll('.custody-file-picker').forEach((picker) => {
        const input = picker.querySelector('input[type="file"]');
        const text = picker.querySelector('span');
        const selected = picker.parentElement?.querySelector('.custody-selected-file');

        if (text) {
            text.textContent = normalize(text.textContent).includes('replace')
                ? 'Choose replacement'
                : 'Choose scan';
        }

        if (input && selected) {
            const refresh = () => {
                const file = input.files?.[0];
                selected.textContent = file?.name || '';
                selected.classList.toggle('has-file', Boolean(file));
            };

            input.addEventListener('change', refresh);
            refresh();
        }
    });
});
</script>

<style>
/* CUSTODY RETURN HISTORY EXPANDABLE DETAILS V3.2 20260822 */

body.custody-ui-v3 .return-history-summary-row {
    cursor: pointer;
    transition: background-color 140ms ease, box-shadow 140ms ease;
}

body.custody-ui-v3 .return-history-summary-row:hover,
body.custody-ui-v3 .return-history-summary-row.is-open {
    background: #f4f8ff;
}

body.custody-ui-v3 .return-history-summary-row:hover td:first-child,
body.custody-ui-v3 .return-history-summary-row.is-open td:first-child {
    box-shadow: inset 3px 0 0 #1769e0;
}

.return-history-toggle {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    max-width: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    color: #174a7a;
    cursor: pointer;
    font: inherit;
    font-weight: 750;
    text-align: left;
}

.return-history-toggle:hover {
    color: #1769e0;
}

.return-history-toggle:focus-visible {
    border-radius: 4px;
    outline: 3px solid rgba(23, 105, 224, .2);
    outline-offset: 4px;
}

.return-history-toggle__chevron {
    flex: 0 0 18px;
    width: 18px;
    height: 18px;
    transition: transform 150ms ease;
}

.return-history-toggle[aria-expanded="true"]
.return-history-toggle__chevron {
    transform: rotate(180deg);
}

.return-history-detail-row[hidden] {
    display: none !important;
}

body.custody-ui-v3 .return-history-detail-row > td {
    padding: 0 !important;
    border-top: 0 !important;
    background: #f8fbff;
}

.return-history-detail-panel {
    padding: 15px 16px 16px;
    border-top: 1px solid #d6e3f1;
    border-bottom: 1px solid #d6e3f1;
}

.return-history-detail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 11px;
}

.return-history-detail-header .eyebrow {
    margin: 0 0 2px;
}

.return-history-detail-header h3 {
    margin: 0;
    color: #0b2854;
    font-size: 14px;
}

.return-history-detail-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 6px;
}

.return-history-detail-summary span {
    display: inline-flex;
    align-items: center;
    min-height: 25px;
    padding: 4px 8px;
    border: 1px solid #cbd9e8;
    border-radius: 999px;
    background: #fff;
    color: #3e5873;
    font-size: 10px;
    font-weight: 750;
}

.return-history-items-wrap {
    overflow-x: auto;
    border: 1px solid #d5e0ec;
    border-radius: 9px;
    background: #fff;
}

body.custody-ui-v3 .return-history-items-table {
    width: 100%;
    min-width: 720px;
    border-collapse: collapse;
}

body.custody-ui-v3 .return-history-items-table th,
body.custody-ui-v3 .return-history-items-table td {
    padding: 10px 12px;
    vertical-align: middle;
}

body.custody-ui-v3 .return-history-items-table th {
    background: #eef3f8;
    color: #38536f;
    font-size: 9.5px;
    letter-spacing: .025em;
    text-transform: uppercase;
}

body.custody-ui-v3 .return-history-items-table td {
    border-top: 1px solid #e5ebf2;
    color: #2d465f;
    font-size: 11px;
}

body.custody-ui-v3 .return-history-items-table td:first-child {
    width: 32%;
}

.return-history-items-table td:first-child strong,
.return-history-items-table td:first-child small {
    display: block;
}

.return-history-items-table td:first-child strong {
    color: #102f50;
    font-size: 12px;
}

.return-history-items-table td:first-child small {
    margin-top: 2px;
    color: #728297;
    font-size: 10px;
}

.return-history-quantity {
    color: #1769e0;
    font-size: 12px;
}

.return-history-condition {
    display: inline-flex;
    align-items: center;
    min-height: 23px;
    padding: 3px 8px;
    border-radius: 999px;
    background: #edf1f5;
    color: #52667c;
    font-size: 9.5px;
    font-weight: 800;
    white-space: nowrap;
}

.return-history-condition.is-fine {
    background: #e5f5ed;
    color: #187453;
}

.return-history-condition.is-damaged,
.return-history-condition.is-destroyed {
    background: #fff0df;
    color: #9a5600;
}

.return-history-condition.is-missing,
.return-history-condition.is-lost,
.return-history-condition.is-stolen {
    background: #fde8e8;
    color: #a52a2a;
}

.return-history-return-note {
    margin-top: 10px;
    padding: 10px 12px;
    border-left: 3px solid #7da7d4;
    border-radius: 0 7px 7px 0;
    background: #eef5fc;
}

.return-history-return-note strong {
    display: block;
    margin-bottom: 3px;
    color: #244b70;
    font-size: 10.5px;
}

.return-history-return-note p {
    margin: 0;
    color: #506b84;
    font-size: 11px;
    line-height: 1.45;
}

.return-history-detail-empty {
    padding: 15px;
    border: 1px dashed #cbd9e8;
    border-radius: 9px;
    background: #fff;
    color: #6a7c90;
    font-size: 11px;
    text-align: center;
}

@media (max-width: 700px) {
    .return-history-detail-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .return-history-detail-summary {
        justify-content: flex-start;
    }
}

@media (prefers-reduced-motion: reduce) {
    .return-history-toggle__chevron,
    body.custody-ui-v3 .return-history-summary-row {
        transition: none;
    }
}
</style>

<script>
/* CUSTODY RETURN HISTORY EXPANDABLE DETAILS V3.2 20260822 */

document.addEventListener('DOMContentLoaded', () => {
    const rows = [
        ...document.querySelectorAll(
            '[data-return-history-row]'
        )
    ];

    const setOpen = (row, open) => {
        const detailId =
            row.dataset.returnDetails;

        const detail = detailId
            ? document.getElementById(detailId)
            : null;

        const button =
            row.querySelector(
                '.return-history-toggle'
            );

        if (!detail || !button) {
            return;
        }

        row.classList.toggle(
            'is-open',
            open
        );

        detail.hidden = !open;

        button.setAttribute(
            'aria-expanded',
            String(open)
        );
    };

    const toggle = (row) => {
        const button =
            row.querySelector(
                '.return-history-toggle'
            );

        const willOpen =
            button?.getAttribute(
                'aria-expanded'
            ) !== 'true';

        rows.forEach((otherRow) => {
            if (otherRow !== row) {
                setOpen(otherRow, false);
            }
        });

        setOpen(row, willOpen);
    };

    rows.forEach((row) => {
        row.addEventListener(
            'click',
            () => toggle(row)
        );
    });
});
</script>

{{-- RETURN HISTORY REMARKS REMOVED V3.3 20260822 --}}

{{-- RETURN HISTORY BLANK REMARKS FIX V3.3.1 20260822 --}}

{{-- FINAL RETURN REMARKS COLUMN FIX V3.3.2 20260822 --}}
@endsection
