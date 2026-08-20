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
                · {{ $custody->borrower->full_name }}
            @endif
        </p>
    </div>
    <x-status-badge :status="$custody->status" />
</section>

@if(session('status'))
    <section class="content-area">
        <div class="callout success">{{ session('status') }}</div>
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
                <h2>Dates and pickup</h2>
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

        <p class="meta">
            The Expected Return Date is the operational return deadline used by the current custody workflow.
        </p>
    </article>

    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Physical documents</p>
                <h2>Generated operational forms</h2>
            </div>
        </div>

        @forelse($documents->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED']) as $document)
            <div class="evidence-row">
                <div>
                    <strong>{{ str($document->document_type)->replace('_', ' ')->title() }}</strong>
                    <small>{{ $document->status }}</small>
                </div>
                <a class="button secondary small" href="{{ route('documents.download', $document) }}">
                    Download
                </a>
            </div>
        @empty
            <div class="empty-state">
                <strong>No operational form generated yet.</strong>
            </div>
        @endforelse
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
    <section class="content-grid two">
        <form method="post" action="{{ route('custody.schedule-pickup', $custody) }}" class="card form-grid">
            @csrf
            <div class="card-header">
                <div>
                    <p class="eyebrow">SPMU pickup</p>
                    <h2>Schedule pickup window</h2>
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

            <p class="meta">
                Pickup must be on the approved Schedule Date. Expiration is the claim window only;
                it does not replace the Expected Return Date.
            </p>

            <button class="button primary ui-pressable">Save Pickup Schedule</button>
        </form>

        <form method="post" action="{{ route('custody.prepare', $custody) }}" class="card form-grid">
            @csrf
            <div class="card-header">
                <div>
                    <p class="eyebrow">Physical preparation</p>
                    <h2>Confirm Preparation &amp; Generate Physical Forms</h2>
                </div>
                <x-status-badge
                    :status="$preparationComplete ? 'VERIFIED' : 'PENDING'"
                    :label="$preparationComplete ? 'Prepared' : 'Pending'"
                />
            </div>

            <p>
                Prepare exact approved quantity for every item after physically counting and inspecting the approved property.
            </p>

            <button class="button primary ui-pressable" @disabled(!$hasPickupSchedule)>
                Confirm Preparation
            </button>
        </form>
    </section>

    <section class="content-area">
        <form method="post" action="{{ route('custody.quantities', $custody) }}" class="card form-grid">
            @csrf
            <div class="card-header">
                <div>
                    <p class="eyebrow">Release quantities</p>
                    <h2>Final prepared quantities</h2>
                </div>
            </div>

            <p>
                Prepared quantity must exactly match the verified approved quantity. If the quantity must change, return the request for correction before release.
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Approved</th>
                            <th>Final prepared</th>
                            <th>Reduction reason</th>
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
                                        step="0.001"
                                        min="0"
                                        max="{{ $line->approved_quantity }}"
                                        name="quantities[{{ $line->id }}]"
                                        value="{{ old('quantities.'.$line->id, $line->quantity_to_receive) }}"
                                        required
                                    >
                                </td>
                                <td>
                                    <input
                                        name="reasons[{{ $line->id }}]"
                                        value="{{ old('reasons.'.$line->id, $line->adjustment_reason) }}"
                                        placeholder="Required if reduced"
                                    >
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button class="button secondary ui-pressable">Save Final Quantities</button>
        </form>
    </section>
@endif

@if($isBorrower && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at && !$custody->acknowledged_at)
    <section class="content-area">
        <form method="post" action="{{ route('custody.acknowledge', $custody) }}" class="card form-grid">
            @csrf

            <div class="card-header">
                <div>
                    <p class="eyebrow">Borrower's Slip review</p>
                    <h2>Review & Confirm Prepared Quantities</h2>
                </div>

                <x-status-badge
                    status="PENDING"
                    label="Confirmation required"
                />
            </div>

            <p>
                Review the final quantities prepared by SPMU before physical release.
                Confirm only after the listed items and quantities are correct.
            </p>

            <p class="meta">
                This is an authenticated system confirmation only and does not create
                an electronic signature. All required signatures must still be completed
                by hand on the printed physical documents during the actual handover.
            </p>

            <button type="submit" class="button primary ui-pressable">
                Confirm Prepared Quantities
            </button>
        </form>
    </section>
@endif

@if($isBorrower && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at && $custody->acknowledged_at && !$custody->released_at)
    <section class="content-area">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Borrower's Slip review</p>
                    <h2>Prepared Quantities Confirmed</h2>
                </div>

                <x-status-badge
                    status="VERIFIED"
                    label="Confirmed"
                />
            </div>

            <p>
                Your system confirmation has been recorded. Please proceed with the
                physical handover and complete all required handwritten/wet signatures
                on the printed documents.
            </p>

            <p class="meta">
                Confirmed {{ optional($custody->acknowledged_at)->format('d F Y, g:i A') }}.
                No electronic signature was created.
            </p>
        </article>
    </section>
@endif

@if($isSpmuOfficer && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at && !$custody->acknowledged_at)
    <section class="content-area">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Physical handover</p>
                    <h2>Waiting for Borrower Confirmation</h2>
                </div>

                <x-status-badge
                    status="PENDING"
                    label="Borrower confirmation required"
                />
            </div>

            <p>
                The final quantities have been prepared. The borrower must review and
                confirm the prepared Borrower's Slip before SPMU can record the physical release.
            </p>

            <p class="meta">
                Borrower confirmation is a system confirmation only. Required signatures
                remain handwritten/wet signatures on the printed physical documents.
            </p>
        </article>
    </section>
@endif

@if($isSpmuOfficer && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at && $custody->acknowledged_at)
    <section class="content-area">
        <form method="post" action="{{ route('custody.release', $custody) }}" class="card form-grid">
            @csrf
            <div class="card-header">
                <div>
                    <p class="eyebrow">Physical handover</p>
                    <h2>Record physical release</h2>
                </div>
                <x-status-badge
                    status="READY_FOR_RELEASE"
                    label="Ready for physical release"
                />
            </div>

            <label class="checkbox">
                <input
                    type="checkbox"
                    name="physical_signatures_confirmed"
                    value="1"
                    required
                >
                I confirm the actual items were checked and all required signatures were completed by hand on the printed physical documents.
            </label>

            <label>
                Release Remarks
                <textarea
                    name="remarks"
                    placeholder="Optional physical handover note"
                ></textarea>
            </label>

            <button class="button primary ui-pressable">
                Mark Issued
            </button>
        </form>
    </section>
@endif

@if($isSpmuOfficer && $custody->released_at)
    <section class="content-grid two">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Issued record</p>
                    <h2>Physical issuance completed</h2>
                </div>
            </div>

            <dl class="detail-list">
                <dt>Issued</dt>
                <dd>{{ optional($custody->released_at)->format('d F Y, g:i A') }}</dd>

                <dt>Status</dt>
                <dd><x-status-badge :status="$custody->status" /></dd>
            </dl>

            <p class="meta">
                The current workflow keeps the issued record read-only on this screen. Return inspection is recorded separately below.
            </p>
        </article>

        @if($custody->gatePass)
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Off-campus verification</p>
                        <h2>Guard-signed Gate Pass</h2>
                    </div>
                    <x-status-badge :status="$custody->gatePass->status" />
                </div>

                @if($custody->gatePass->status !== 'VERIFIED')
                    <form method="post" action="{{ route('gate-passes.verify', $custody->gatePass) }}" class="form-grid">
                        @csrf
                        <p>
                            Complete this only after the signed Gate Pass evidence has already been uploaded and verified.
                        </p>

                        <label>
                            Guard on Duty
                            <input name="guard_name" required>
                        </label>

                        <label>
                            Guard Signed Date & Time
                            <input type="datetime-local" name="guard_signed_at" required>
                        </label>

                        <button class="button primary ui-pressable">Complete Gate Pass Verification</button>
                    </form>
                @else
                    <dl class="detail-list">
                        <dt>Guard</dt>
                        <dd>{{ $custody->gatePass->guard_name ?: '—' }}</dd>

                        <dt>Signed</dt>
                        <dd>{{ optional($custody->gatePass->guard_signed_at)->format('d M Y, g:i A') ?: '—' }}</dd>
                    </dl>
                @endif
            </article>
        @endif
    </section>
@endif

@if($laundryJob)
    <section class="content-area">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Laundry workflow</p>
                    <h2>Laundry service status</h2>
                </div>
                <x-status-badge :status="$laundryJob->status" />
            </div>

            <p>
                Laundry-required linen is received and physically inspected by the Laundry Worker.
                The accomplished handwritten Laundry Form is uploaded by Laundry, then reviewed by
                the SPMU Action Officer before the final property return is closed.
            </p>

            @if($laundryJob->latestEvidence)
                <div class="evidence-row top-gap">
                    <div>
                        <strong>Accomplished Laundry Form</strong>
                        <small>
                            Uploaded {{ optional($laundryJob->latestEvidence->submitted_at)->format('d M Y, g:i A') ?: '—' }}
                            · {{ str($laundryJob->latestEvidence->verification_status)->replace('_', ' ')->title() }}
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
                    <strong>No accomplished Laundry Form uploaded yet.</strong>
                    <p>
                        The Laundry Worker must physically inspect the linen, complete the printed form,
                        and upload the accomplished scan from the Laundry workspace.
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
                    <h2>Record returned items</h2>
                </div>
            </div>

            <p class="meta">
                Enter only quantities physically received and inspected by SPMU. Inventory and accountability effects are applied by the current return workflow.
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Outstanding</th>
                            <th>Returned Qty</th>
                            <th>Condition / Finding</th>
                            <th>Evidence</th>
                            <th>Police Reference</th>
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
                Inspection Remarks
                <textarea name="remarks">{{ old('remarks') }}</textarea>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="early_return" value="1" @checked(old('early_return'))>
                Record this as an early physical return.
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
                <h2>Recorded returns</h2>
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
                    @forelse($custody->returns->sortByDesc('id') as $return)
                        <tr>
                            <td>{{ $return->return_no }}</td>
                            <td>{{ optional($return->received_at)->format('d M Y, g:i A') ?: '—' }}</td>
                            <td>{{ str($return->return_type ?: 'NORMAL')->replace('_', ' ')->title() }}</td>
                            <td><x-status-badge :status="$return->status" /></td>
                            <td>{{ $return->remarks ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">No return recorded.</td>
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
@endsection
