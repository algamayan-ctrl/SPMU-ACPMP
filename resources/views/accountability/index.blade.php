@extends('layouts.app', ['title' => 'Accountability'])

@section('content')

@php
    $workspace = session('active_workspace');

    $activeRestrictions = $workspace === 'BORROWER'
        ? $restrictions->where('status', 'ACTIVE')
        : collect();

    $openOverdueCount = $workspace === 'BORROWER'
        ? $overdueCases->whereNotIn('status', ['RESOLVED'])->count()
        : 0;

    $openIncidentCount = $workspace === 'BORROWER'
        ? $incidents->whereNotIn('status', ['RESOLVED', 'CLOSED'])->count()
        : 0;

    $unpaidBillingCount = $workspace === 'BORROWER'
        ? $billings->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID'])->count()
        : 0;
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Borrowing responsibility</p>

        <h1>
            {{ $workspace === 'BORROWER' ? 'My Accountability' : 'Accountability records' }}
        </h1>
    </div>
</section>

@if($workspace === 'BORROWER')

    {{-- ====================================================== --}}
    {{-- ACTIVE RESTRICTION NOTICE                              --}}
    {{-- ====================================================== --}}

    @if($activeRestrictions->isNotEmpty())

        <section class="content-area">
            <div class="action-panel action-warning">

                <div>
                    <p class="eyebrow">Borrowing temporarily restricted</p>

                    <h2>
                        Resolve the outstanding accountability record
                    </h2>

                    <p>
                        A new borrowing request cannot be submitted while an
                        active restriction remains. Review the related overdue,
                        incident, billing, or restriction record below.
                    </p>
                </div>

                <x-status-badge
                    status="ACTIVE"
                    label="Restriction active"
                />

            </div>
        </section>

    @endif

    {{-- ====================================================== --}}
    {{-- BORROWER ACCOUNTABILITY SUMMARY                        --}}
    {{-- ====================================================== --}}

    <section class="content-area">

        <div
            class="accountability-summary"
            aria-label="My accountability summary"
        >
            <span>
                <strong>{{ $openOverdueCount }}</strong>
                Open Overdue
            </span>

            <span>
                <strong>{{ $openIncidentCount }}</strong>
                Open Incidents
            </span>

            <span>
                <strong>{{ $unpaidBillingCount }}</strong>
                Unpaid Billings
            </span>

            <span>
                <strong>{{ $activeRestrictions->count() }}</strong>
                Active Restrictions
            </span>
        </div>

    </section>

    {{-- ====================================================== --}}
    {{-- OVERDUE CASES                                          --}}
    {{-- ====================================================== --}}

    <section class="content-area accountability-section">

        <div class="section-heading">
            <div>
                <p class="eyebrow">Deadline compliance</p>
                <h2>Overdue Cases</h2>
            </div>
        </div>

        <div class="accountability-list">

            @forelse($overdueCases as $overdue)

                <article class="card accountability-record">

                    <div class="record-heading">
                        <div>
                            <span class="record-id">
                                {{ $overdue->custody->custody_no }}
                            </span>

                            <h3>
                                Overdue return · Offense level
                                {{ $overdue->offense_level }}
                            </h3>
                        </div>

                        <x-status-badge :status="$overdue->status" />
                    </div>

                    <dl class="summary-grid compact">

                        <div>
                            <dt>Grace period ended</dt>
                            <dd>
                                {{ $overdue->grace_expires_at->format('d M Y') }}
                            </dd>
                        </div>

                        <div>
                            <dt>Official daily rate</dt>
                            <dd>
                                {{
                                    $overdue->rate_snapshot === null
                                        ? 'Awaiting configured assessment'
                                        : 'PHP '
                                            .number_format(
                                                (float) $overdue->rate_snapshot,
                                                2
                                            )
                                            .' per day'
                                }}
                            </dd>
                        </div>

                        <div>
                            <dt>Recorded amount</dt>
                            <dd>
                                {{
                                    $overdue->rate_snapshot === null
                                        ? 'Not yet determined'
                                        : 'PHP '
                                            .number_format(
                                                (float) $overdue->accrued_amount,
                                                2
                                            )
                                }}
                            </dd>
                        </div>

                        <div>
                            <dt>Required action</dt>
                            <dd>
                                {{
                                    in_array(
                                        $overdue->status,
                                        ['RESOLVED'],
                                        true
                                    )
                                        ? 'No action required.'
                                        : 'Coordinate the return or settlement with SPMU.'
                                }}
                            </dd>
                        </div>

                    </dl>

                    <div class="actions">
                        <a
                            class="button secondary small ui-pressable"
                            href="{{ route('custody.show', $overdue->custody) }}"
                        >
                            View Related Borrowing
                        </a>
                    </div>

                </article>

            @empty

                <div class="empty-state borrower-empty-state">
                    <div>
                        <strong>No overdue cases.</strong>

                        <span>
                            You currently have no recorded overdue return obligation.
                        </span>
                    </div>
                </div>

            @endforelse

        </div>
    </section>

    {{-- ====================================================== --}}
    {{-- INCIDENTS                                              --}}
    {{-- ====================================================== --}}

    <section class="content-area accountability-section">

        <div class="section-heading">
            <div>
                <p class="eyebrow">Damage, loss, or theft</p>
                <h2>Incidents</h2>
            </div>
        </div>

        <div class="accountability-list">

            @forelse($incidents as $incident)

                <article class="card accountability-record">

                    <div class="record-heading">
                        <div>
                            <span class="record-id">
                                {{ $incident->incident_no }}
                            </span>

                            <h3>
                                {{
                                    str($incident->incident_type)
                                        ->replace('_', ' ')
                                        ->lower()
                                        ->title()
                                }}
                                incident
                            </h3>
                        </div>

                        <x-status-badge :status="$incident->status" />
                    </div>

                    @if($incident->remarks)
                        <p class="record-note">
                            {{ $incident->remarks }}
                        </p>
                    @endif

                    @if($incident->lines->isNotEmpty())

                        <div class="incident-outcomes">

                            @foreach($incident->lines as $line)

                                <span>
                                    <strong>{{ $line->quantity + 0 }}</strong>
                                    ·
                                    {{
                                        str($line->observed_condition)
                                            ->replace('_', ' ')
                                            ->lower()
                                            ->title()
                                    }}
                                    ·
                                    {{
                                        str($line->disposition_state)
                                            ->replace('_', ' ')
                                            ->lower()
                                            ->title()
                                    }}
                                </span>

                            @endforeach

                        </div>

                    @endif

                    <dl class="summary-grid compact">

                        <div>
                            <dt>Reported</dt>
                            <dd>
                                {{ $incident->reported_at->format('d M Y') }}
                            </dd>
                        </div>

                        <div>
                            <dt>Current obligation</dt>
                            <dd>
                                {{
                                    in_array(
                                        $incident->status,
                                        ['RESOLVED', 'CLOSED'],
                                        true
                                    )
                                        ? 'Resolved'
                                        : (
                                            $incident->status === 'BILLING_PENDING'
                                                ? 'Review the related Billing Statement.'
                                                : 'Await SPMU assessment or follow-up.'
                                        )
                                }}
                            </dd>
                        </div>

                        @if($incident->police_blotter_reference)
                            <div>
                                <dt>Police / blotter reference</dt>
                                <dd>
                                    {{ $incident->police_blotter_reference }}
                                </dd>
                            </div>
                        @endif

                    </dl>

                    <div class="actions">

                        @if($incident->supporting_evidence_file_id)
                            <a
                                class="button secondary small ui-pressable"
                                href="{{ route('files.show', $incident->supporting_evidence_file_id) }}"
                                target="_blank"
                                rel="noopener"
                            >
                                View Incident Evidence
                            </a>
                        @endif

                        @if($incident->custody)
                            <a
                                class="button ghost small ui-pressable"
                                href="{{ route('custody.show', $incident->custody) }}"
                            >
                                View Related Borrowing
                            </a>
                        @endif

                    </div>

                </article>

            @empty

                <div class="empty-state borrower-empty-state">
                    <div>
                        <strong>No damage, loss, or theft incidents.</strong>

                        <span>
                            No property incident is currently recorded
                            against your borrowings.
                        </span>
                    </div>
                </div>

            @endforelse

        </div>
    </section>

    {{-- ====================================================== --}}
    {{-- BILLING STATEMENTS                                     --}}
    {{-- ====================================================== --}}

    <section class="content-area accountability-section">

        <div class="section-heading">
            <div>
                <p class="eyebrow">Official charges and payments</p>
                <h2>Billing Statements</h2>
            </div>
        </div>

        <div class="accountability-list">

            @forelse($billings as $billing)

                <article class="card accountability-record billing-record">

                    <div class="record-heading">
                        <div>
                            <span class="record-id">
                                {{ $billing->billing_no }}
                            </span>

                            <h3>
                                PHP
                                {{ number_format((float) $billing->total_amount, 2) }}
                            </h3>
                        </div>

                        <x-status-badge :status="$billing->status" />
                    </div>

                    <dl class="summary-grid compact">

                        <div>
                            <dt>Date issued</dt>
                            <dd>
                                {{
                                    optional($billing->issued_at)->format('d M Y')
                                        ?: 'Not recorded'
                                }}
                            </dd>
                        </div>

                        <div>
                            <dt>Payment deadline</dt>
                            <dd>
                                {{
                                    optional($billing->due_at)->format('d M Y')
                                        ?: 'No deadline recorded'
                                }}
                            </dd>
                        </div>

                    </dl>

                    @if($billing->lines->isNotEmpty())

                        <div class="billing-lines">

                            @foreach($billing->lines as $line)

                                <p>
                                    <strong>
                                        {{
                                            str($line->line_type)
                                                ->replace('_', ' ')
                                                ->lower()
                                                ->title()
                                        }}
                                    </strong>

                                    <span>
                                        {{ $line->description }}
                                    </span>

                                    <small>
                                        PHP
                                        {{ number_format((float) $line->amount, 2) }}
                                    </small>
                                </p>

                            @endforeach

                        </div>

                    @endif

                    <div class="actions">

                        @foreach(
                            $billing->documents
                                ->whereNotIn(
                                    'status',
                                    [
                                        'SUPERSEDED',
                                        'INVALIDATED',
                                        'EXPIRED',
                                    ]
                                )
                            as $document
                        )

                            <a
                                class="button secondary small ui-pressable"
                                href="{{ route('documents.download', $document) }}"
                            >
                                Download Billing Statement
                            </a>

                        @endforeach

                    </div>

                    @if($billing->status === 'ISSUED')

                        <form
                            method="post"
                            action="{{ route('payments.store', $billing) }}"
                            enctype="multipart/form-data"
                            class="form-grid payment-upload"
                        >
                            @csrf

                            <h4>Upload payment evidence</h4>

                            <p>
                                Upload a copy only after payment through the
                                official payment process. Uploading a receipt
                                does not verify payment. SPMU must inspect the
                                original receipt and record the official receipt details.
                            </p>

                            <label>
                                Receipt image or PDF

                                <small>
                                    PDF, PNG, JPG, or WebP · maximum 5 MB
                                </small>

                                <input
                                    type="file"
                                    name="evidence"
                                    accept="application/pdf,image/png,image/jpeg,image/webp"
                                    required
                                >
                            </label>

                            <label>
                                Note to SPMU
                                <small>(optional)</small>

                                <textarea name="remarks"></textarea>
                            </label>

                            <button class="button primary ui-pressable">
                                Submit Receipt Copy
                            </button>

                        </form>

                    @elseif($billing->status === 'RECEIPT_SUBMITTED')

                        <div class="callout">
                            <strong>Receipt copy submitted</strong>

                            <p>
                                Bring the original receipt to SPMU.
                                The uploaded copy does not equal payment verification.
                            </p>
                        </div>

                    @endif

                    <div class="payment-history">

                        @forelse($billing->payments as $payment)

                            <div class="evidence-row">
                                <div>

                                    <x-status-badge :status="$payment->status" />

                                    <strong>
                                        {{
                                            $payment->official_receipt_no
                                                ? 'Official Receipt '
                                                    .$payment->official_receipt_no
                                                : 'Receipt copy submitted'
                                        }}
                                    </strong>

                                    <small>
                                        Submitted
                                        {{
                                            optional($payment->submitted_at)
                                                ->format('d M Y')
                                                ?: 'date not recorded'
                                        }}

                                        @if($payment->amount)
                                            · PHP
                                            {{
                                                number_format(
                                                    (float) $payment->amount,
                                                    2
                                                )
                                            }}
                                        @endif
                                    </small>

                                    @if($payment->rejection_reason)
                                        <p class="text-danger">
                                            {{ $payment->rejection_reason }}
                                        </p>
                                    @endif

                                    @if($payment->evidence_file_id)
                                        <a
                                            class="table-action ui-pressable"
                                            href="{{ route('files.show', $payment->evidence_file_id) }}"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            View Receipt Copy
                                        </a>
                                    @endif

                                </div>
                            </div>

                        @empty

                            <p class="meta">
                                No payment evidence has been submitted.
                            </p>

                        @endforelse

                    </div>

                </article>

            @empty

                <div class="empty-state borrower-empty-state">
                    <div>
                        <strong>No billing statements.</strong>

                        <span>
                            No official charge is currently recorded
                            against your account.
                        </span>
                    </div>
                </div>

            @endforelse

        </div>
    </section>

    {{-- ====================================================== --}}
    {{-- BORROWING RESTRICTIONS                                 --}}
    {{-- ====================================================== --}}

    <section class="content-area accountability-section">

        <article class="card">

            <div class="card-header">
                <div>
                    <p class="eyebrow">Borrowing eligibility</p>
                    <h2>Restrictions</h2>
                </div>

                <x-status-badge
                    :status="$activeRestrictions->isNotEmpty() ? 'ACTIVE' : 'INACTIVE'"
                    :label="$activeRestrictions->isNotEmpty() ? 'Active restriction' : 'No active restriction'"
                />
            </div>

            <div class="table-wrap borrower-detail-table">

                <table>
                    <thead>
                        <tr>
                            <th scope="col">Restriction</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Effective period</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        @forelse($restrictions as $restriction)

                            <tr>
                                <td data-label="Restriction">
                                    {{
                                        str($restriction->restriction_type)
                                            ->replace('_', ' ')
                                            ->lower()
                                            ->title()
                                    }}
                                </td>

                                <td data-label="Reason">
                                    {{ $restriction->reason }}
                                </td>

                                <td data-label="Effective period">
                                    {{ $restriction->effective_from->format('d M Y') }}

                                    {{
                                        $restriction->effective_to
                                            ? ' to '
                                                .$restriction->effective_to->format('d M Y')
                                            : ' until resolved'
                                    }}
                                </td>

                                <td data-label="Status">
                                    <x-status-badge :status="$restriction->status" />
                                </td>
                            </tr>

                        @empty

                            <tr>
                                <td
                                    colspan="4"
                                    class="empty-state"
                                >
                                    <strong>No borrowing restrictions.</strong>

                                    <span>
                                        You currently have no restriction
                                        recorded against your account.
                                    </span>
                                </td>
                            </tr>

                        @endforelse

                    </tbody>
                </table>

            </div>
        </article>
    </section>

@else
@php
    $activeRestrictions = $restrictions->where('status','ACTIVE');
@endphp
<section class="content-area"><div class="accountability-summary operational-summary" aria-label="Accountability operations summary"><span><strong>{{ $overdueCases->whereNotIn('status',['RESOLVED'])->count() }}</strong> overdue case(s)</span><span><strong>{{ $incidents->whereNotIn('status',['RESOLVED','CLOSED'])->count() }}</strong> open incident(s)</span><span><strong>{{ $billings->whereNotIn('status',['SETTLED','WAIVED','VOID'])->count() }}</strong> billing(s) awaiting closure</span><span><strong>{{ $activeRestrictions->count() }}</strong> active restriction(s)</span></div></section>

<section class="content-area accountability-section"><div class="section-heading"><div><p class="eyebrow">Deadline compliance</p><h2>Overdue cases</h2></div></div><div class="accountability-list">
@forelse($overdueCases as $overdue)
<article class="card accountability-record"><div class="record-heading"><div><span class="record-id">{{ $overdue->custody->custody_no }}</span><h3>{{ $overdue->borrower->full_name }}</h3></div><x-status-badge :status="$overdue->status" /></div><dl class="summary-grid compact"><div><dt>Offense level</dt><dd>{{ $overdue->offense_level }}</dd></div><div><dt>Sanction</dt><dd>{{ str($overdue->sanction_type)->replace('_',' ')->lower()->title() }}</dd></div><div><dt>Grace period ended</dt><dd><x-date :value="$overdue->grace_expires_at" with-time /></dd></div><div><dt>Official daily rate</dt><dd>{{ $overdue->rate_snapshot===null ? 'Not configured' : 'PHP '.number_format((float)$overdue->rate_snapshot,2).' per day' }}</dd></div><div><dt>Recorded amount</dt><dd>{{ $overdue->rate_snapshot===null ? 'Amount not yet determined' : 'PHP '.number_format((float)$overdue->accrued_amount,2) }}</dd></div><div><dt>Required action</dt><dd>{{ $overdue->status==='RETURNED_PENDING_SETTLEMENT' ? 'Issue or settle the official assessment.' : 'Review the related custody record.' }}</dd></div></dl><div class="actions"><a class="button secondary small ui-pressable" href="{{ route('custody.show',$overdue->custody) }}">View custody</a></div>@if($workspace==='SPMU' && $overdue->status==='RETURNED_PENDING_SETTLEMENT' && !$overdue->penalties->where('status','!=','VOID')->count())<form method="post" action="{{ route('overdue.bill',$overdue) }}" class="form-grid operational-action-form">@csrf<h4>Generate overdue Billing Statement</h4><label>Assessment basis<textarea name="basis" required>Configured daily tariff × recorded overdue days after the 24-hour grace period.</textarea></label><label>Payment due date <small>Optional</small><input type="date" name="due_at"></label><button class="button primary ui-pressable">Generate Billing Statement</button></form>@endif</article>
@empty<div class="empty-state borrower-empty-state"><div><strong>No overdue cases.</strong><span>No deadline-compliance case requires review.</span></div></div>@endforelse
</div></section>

<section class="content-area accountability-section"><div class="section-heading"><div><p class="eyebrow">Property responsibility</p><h2>Incidents</h2></div></div><div class="accountability-list">
@forelse($incidents as $incident)
<article class="card accountability-record"><div class="record-heading"><div><span class="record-id">{{ $incident->incident_no }}</span><h3>{{ str($incident->incident_type)->replace('_',' ')->lower()->title() }} incident</h3></div><x-status-badge :status="$incident->status" /></div><dl class="summary-grid compact"><div><dt>Borrower</dt><dd>{{ $incident->borrower->full_name }}</dd></div><div><dt>Custody / Request</dt><dd><a href="{{ route('custody.show',$incident->custody) }}">{{ $incident->custody->custody_no }}</a><small>{{ $incident->custody->request->request_no }}</small></dd></div><div><dt>Incident date</dt><dd><x-date :value="$incident->reported_at" with-time /></dd></div><div><dt>Billing status</dt><dd>{{ $incident->status==='BILLING_PENDING' ? 'Billing pending settlement' : ($incident->status==='OPEN' ? 'Assessment not yet billed' : str($incident->status)->replace('_',' ')->lower()->title()) }}</dd></div>@if($incident->police_blotter_reference)<div><dt>Police / blotter reference</dt><dd>{{ $incident->police_blotter_reference }}</dd></div>@endif<div><dt>Restriction</dt><dd>{{ $activeRestrictions->where('incident_id',$incident->id)->isNotEmpty() ? 'Active restriction' : 'No active linked restriction' }}</dd></div></dl>@if($incident->remarks)<p class="record-note">{{ $incident->remarks }}</p>@endif @if($incident->lines->isNotEmpty())<div class="incident-outcomes">@foreach($incident->lines as $line)<span><strong>{{ $line->quantity+0 }}</strong> · {{ str($line->observed_condition)->replace('_',' ')->lower()->title() }} · {{ str($line->disposition_state)->replace('_',' ')->lower()->title() }}</span>@endforeach</div>@endif<div class="actions">@if($incident->supporting_evidence_file_id)<a class="button secondary small ui-pressable" href="{{ route('files.show',$incident->supporting_evidence_file_id) }}" target="_blank" rel="noopener">View incident evidence</a>@endif<a class="button ghost small" href="{{ route('custody.show',$incident->custody) }}">View custody</a></div>@if($workspace==='SPMU' && $incident->status==='OPEN')<form method="post" action="{{ route('incidents.bill',$incident) }}" class="form-grid operational-action-form">@csrf<h4>Generate property-charge Billing Statement</h4><div class="form-columns"><label>Authorized charge amount<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Payment due date <small>Optional</small><input type="date" name="due_at"></label></div><label>Assessment basis<textarea name="basis" required></textarea></label><button class="button primary ui-pressable">Generate Billing Statement</button></form>@endif</article>
@empty<div class="empty-state borrower-empty-state"><div><strong>No open incidents.</strong><span>No property-responsibility incident is recorded.</span></div></div>@endforelse
</div></section>

<section class="content-area accountability-section"><div class="section-heading"><div><p class="eyebrow">Billing and settlement</p><h2>Billing statements</h2></div></div><div class="accountability-list">
@forelse($billings as $billing)
<article class="card accountability-record billing-record"><div class="record-heading"><div><span class="record-id">{{ $billing->billing_no }}</span><h3>{{ $billing->borrower->full_name }}</h3></div><x-status-badge :status="$billing->status" /></div><dl class="summary-grid compact"><div><dt>Official amount</dt><dd><strong>PHP {{ number_format((float)$billing->total_amount,2) }}</strong></dd></div><div><dt>Date issued</dt><dd><x-date :value="$billing->issued_at" fallback="Not recorded" /></dd></div><div><dt>Payment deadline</dt><dd><x-date :value="$billing->due_at" fallback="No deadline recorded" /></dd></div><div><dt>Payment status</dt><dd>{{ str($billing->status)->replace('_',' ')->lower()->title() }}</dd></div></dl><div class="billing-lines">@foreach($billing->lines as $line)<p><strong>{{ str($line->line_type)->replace('_',' ')->lower()->title() }}</strong><span>{{ $line->description }}</span><small>PHP {{ number_format((float)$line->amount,2) }}</small></p>@endforeach</div><div class="actions">@foreach($billing->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)<a class="button secondary small ui-pressable" href="{{ route('documents.download',$document) }}">Download Billing Statement</a>@endforeach</div>
<div class="payment-history"><h4>Payment evidence and verification</h4>@forelse($billing->payments as $payment)<div class="evidence-row operational-evidence-row"><div><x-status-badge :status="$payment->status" /><strong>{{ $payment->official_receipt_no ? 'Official Receipt '.$payment->official_receipt_no : 'Payment evidence submitted' }}</strong><small>Submitted <x-date :value="$payment->submitted_at" with-time fallback="Date not recorded" /></small>@if($payment->receipt_date)<small>Receipt date <x-date :value="$payment->receipt_date" />{{ $payment->amount ? ' · Verified amount PHP '.number_format((float)$payment->amount,2) : '' }}</small>@endif @if($payment->verification_remarks)<p>{{ $payment->verification_remarks }}</p>@endif @if($payment->rejection_reason)<p class="text-danger">{{ $payment->rejection_reason }}</p>@endif @if($payment->evidence_file_id)<a class="table-action ui-pressable" href="{{ route('files.show',$payment->evidence_file_id) }}" target="_blank" rel="noopener">View uploaded evidence</a>@endif</div>@if($workspace==='SPMU' && $payment->status==='SUBMITTED_PENDING_ORIGINAL')<form method="post" action="{{ route('payments.verify',$payment) }}" class="form-grid payment-verification-form">@csrf<p><strong>Inspect the original receipt.</strong> Upload alone does not verify payment or lift restrictions.</p><div class="form-columns"><label>Official Receipt number <small>Required to verify</small><input name="official_receipt_no"></label><label>Receipt date <small>Required to verify</small><input type="date" name="receipt_date"></label><label>Official verified amount <small>Required to verify</small><input type="number" step="0.01" min="0.01" name="amount"></label><label>Verification remarks <small>Required for either decision</small><input name="remarks" required></label></div><div class="inline-actions"><button class="button primary ui-pressable" name="decision" value="VERIFIED">Verify payment</button><button class="button danger ui-pressable" name="decision" value="REJECTED">Request replacement</button></div></form>@endif</div>@empty<div class="empty-state"><strong>No payment evidence submitted.</strong><span>Borrower uploads and SPMU verification will appear here.</span></div>@endforelse</div>
@if($workspace==='SPMU' && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))<details class="danger-zone compact-danger top-gap"><summary>Record an authorized waiver</summary><form method="post" action="{{ route('billings.waive',$billing) }}" class="form-grid top-gap">@csrf<label>Authorized waiver reason<textarea name="reason" required></textarea></label><button class="button danger small ui-pressable">Record authorized waiver</button></form></details>@endif</article>
@empty<div class="empty-state borrower-empty-state"><div><strong>No billing statements awaiting action.</strong><span>No official billing record is available.</span></div></div>@endforelse
</div></section>

<section class="content-area accountability-section"><article class="card"><div class="card-header"><div><p class="eyebrow">Borrowing eligibility</p><h2>Restrictions</h2></div><x-status-badge :status="$activeRestrictions->isNotEmpty() ? 'ACTIVE' : 'INACTIVE'" :label="$activeRestrictions->isNotEmpty() ? 'Active restriction' : 'No active restriction'" /></div><div class="table-wrap operational-table"><table><thead><tr><th scope="col">Restriction</th><th scope="col">Reason</th><th scope="col">Effective period</th><th scope="col">Status</th></tr></thead><tbody>@forelse($restrictions as $restriction)<tr><td data-label="Restriction">{{ str($restriction->restriction_type)->replace('_',' ')->lower()->title() }}</td><td data-label="Reason">{{ $restriction->reason }}</td><td data-label="Effective period"><x-date :value="$restriction->effective_from" />{{ $restriction->effective_to ? ' to ' : ' until resolved' }}@if($restriction->effective_to)<x-date :value="$restriction->effective_to" />@endif</td><td data-label="Status"><x-status-badge :status="$restriction->status" /></td></tr>@empty<tr><td colspan="4" class="empty-state"><strong>No active restrictions.</strong><span>No borrowing-eligibility restriction is recorded.</span></td></tr>@endforelse</tbody></table></div></article></section>
@endif
@endsection

