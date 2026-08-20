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

@if($isSpmuOfficer && $custody->status === 'PREPARING_RELEASE' && $custody->prepared_at)
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
                Laundry-required linen follows the Laundry Worker workflow. Final inventory availability remains controlled by the current return and SPMU verification process.
            </p>
        </article>
    </section>
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
