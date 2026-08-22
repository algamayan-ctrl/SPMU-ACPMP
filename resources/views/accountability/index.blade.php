@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Obligations' : (auth()->user()?->access_classification?->value === 'SPMU_HEAD' ? 'Accountability Oversight' : 'Return Issues')])
@section('content')
@php
    $workspace = session('active_workspace');
    $classification = auth()->user()?->access_classification?->value;
    $isOfficer = $classification === 'SPMU_OFFICER';
    $isHead = $classification === 'SPMU_HEAD';
    $pageTitle = $workspace === 'BORROWER' ? 'My Obligations' : ($isHead ? 'Accountability Oversight' : 'Return Issues');

    $activeRestrictions = $restrictions->where('status', 'ACTIVE');
    $openOverdueCases = $overdueCases->whereNotIn('status', ['RESOLVED']);
    $openIncidents = $incidents->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION']);
    $openBillings = $billings->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID']);
    $pendingViolations = $isHead ? $violations->where('status', 'PENDING_REVIEW') : collect();
    $openCaseCount = $openOverdueCases->count() + $openIncidents->count();
    $hasOpenMatters = $openCaseCount > 0
        || $openBillings->isNotEmpty()
        || $activeRestrictions->isNotEmpty()
        || $pendingViolations->isNotEmpty();
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Financial and property accountability</p>
        <h1>{{ $pageTitle }}</h1>
        <p>
            {{ $workspace === 'BORROWER'
                ? 'See unresolved obligations that affect your borrowing eligibility and what you need to resolve next.'
                : ($isHead
                    ? 'Focus on matters that need Head-level oversight or a formal administrative decision.'
                    : 'Process unresolved return, property, billing, and payment-evidence issues.') }}
        </p>
    </div>
</section>

@if($workspace === 'BORROWER' && $activeRestrictions->isNotEmpty())
<section class="content-area">
    <div class="action-panel action-warning">
        <div>
            <p class="eyebrow">Borrowing eligibility</p>
            <h2>Borrowing is currently restricted</h2>
            <p>Resolve the outstanding obligation shown below before submitting another request.</p>
        </div>
        <x-status-badge status="ACTIVE" />
    </div>
</section>
@endif

<section class="stat-grid dashboard-stat-grid" aria-label="Accountability overview">
    @if($isHead)
        <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning">
            <span class="kpi-icon" aria-hidden="true"><x-icon name="accountability" size="18" /></span>
            <strong class="kpi-value">{{ $pendingViolations->count() }}</strong>
            <span class="kpi-label">Needs Head Review</span>
            <small>{{ $pendingViolations->count() ? 'Administrative decision pending' : 'No pending administrative decision' }}</small>
        </article>
        <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-danger">
            <span class="kpi-icon" aria-hidden="true"><x-icon name="custody" size="18" /></span>
            <strong class="kpi-value">{{ $openCaseCount }}</strong>
            <span class="kpi-label">Open Cases</span>
            <small>{{ $openOverdueCases->count() }} overdue · {{ $openIncidents->count() }} property</small>
        </article>
    @else
        <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-danger">
            <span class="kpi-icon" aria-hidden="true"><x-icon name="calendar" size="18" /></span>
            <strong class="kpi-value">{{ $openOverdueCases->count() }}</strong>
            <span class="kpi-label">Overdue Returns</span>
            <small>Unresolved date-based lateness</small>
        </article>
        <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning">
            <span class="kpi-icon" aria-hidden="true"><x-icon name="accountability" size="18" /></span>
            <strong class="kpi-value">{{ $openIncidents->count() }}</strong>
            <span class="kpi-label">Property Cases</span>
            <small>Damage, loss, or accountability findings</small>
        </article>
    @endif

    <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-info">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="requests" size="18" /></span>
        <strong class="kpi-value">{{ $openBillings->count() }}</strong>
        <span class="kpi-label">Open Billings</span>
        <small>Awaiting settlement or disposition</small>
    </article>

    <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="lock" size="18" /></span>
        <strong class="kpi-value">{{ $activeRestrictions->count() }}</strong>
        <span class="kpi-label">Active Restrictions</span>
        <small>Borrowing restrictions currently in force</small>
    </article>
</section>

@if(! $hasOpenMatters)
<section class="content-area">
    <article class="card">
        <div class="empty-state">
            <div>
                <strong>{{ $workspace === 'BORROWER' ? 'You have no unresolved obligations.' : 'No accountability matters need attention.' }}</strong>
                <p>{{ $workspace === 'BORROWER' ? 'There are no open overdue, property, billing, or restriction records on your account.' : 'There are no pending Head decisions, open property cases, unpaid billings, or active restrictions.' }}</p>
            </div>
        </div>
    </article>
</section>
@endif

@if($isHead && $pendingViolations->isNotEmpty())
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Administrative accountability</p>
            <h2>Administrative Review</h2>
            <p>Review only violations that require an SPMU Head decision. Property return findings and financial follow-up stay in their own sections below.</p>
        </div>
    </div>

    @foreach($pendingViolations as $violation)
        <article class="card top-gap">
            <div class="card-header">
                <div>
                    <strong>{{ $violation->custody?->custody_no ?: 'No custody reference' }}</strong>
                    <h3>{{ $violation->borrower->full_name }}</h3>
                    <small>Detected {{ optional($violation->detected_at)->format('d M Y, g:i A') }}</small>
                </div>
                <x-status-badge :status="$violation->status" />
            </div>

            <dl class="summary-grid compact">
                <div>
                    <dt>Finding(s)</dt>
                    <dd>{{ collect(data_get($violation->details_json, 'reasons', []))->map(fn ($reason) => str($reason)->replace('_', ' ')->title())->join(', ') ?: 'Borrowing violation' }}</dd>
                </div>
                <div>
                    <dt>Academic Period</dt>
                    <dd>{{ $violation->academicPeriod ? $violation->academicPeriod->academic_year.' · '.$violation->academicPeriod->term_name : 'Uses active period when confirmed' }}</dd>
                </div>
            </dl>

            <form method="post" action="{{ route('accountability.violations.review', $violation) }}" class="form-grid top-gap">
                @csrf
                <div class="form-columns">
                    <label>
                        Administrative Action
                        <select name="sanction_code">
                            <option value="">Select when confirming</option>
                            <option value="NOTICE">Notice</option>
                            <option value="WRITTEN_REPRIMAND">Written Reprimand</option>
                            <option value="BORROWING_SUSPENSION">Borrowing Suspension</option>
                            <option value="OTHER">Other Administrative Action</option>
                        </select>
                    </label>
                    <label>
                        Suspension Until
                        <input type="date" name="effective_to" min="{{ now()->toDateString() }}">
                        <small>Required only for Borrowing Suspension.</small>
                    </label>
                </div>
                <label>
                    Other Action Label
                    <input name="custom_sanction_label" maxlength="255" placeholder="Complete only when Other is selected">
                </label>
                <label>
                    Review Remarks
                    <textarea name="remarks" maxlength="2000" placeholder="Record the basis for the SPMU Head decision."></textarea>
                </label>
                <div class="inline-actions">
                    <button class="button primary" name="decision" value="CONFIRMED">Confirm Violation & Record Sanction</button>
                    <button class="button secondary" name="decision" value="DISMISSED">Dismiss Violation</button>
                </div>
            </form>
        </article>
    @endforeach
</section>
@endif

@if($openOverdueCases->isNotEmpty())
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Date-based lateness</p>
            <h2>Overdue Returns</h2>
        </div>
    </div>
    @foreach($openOverdueCases as $overdue)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $overdue->custody->custody_no }}</strong><h3>{{ $overdue->borrower->full_name }}</h3></div><x-status-badge :status="$overdue->status" /></div>
<dl class="summary-grid compact"><div><dt>Expected Return Date</dt><dd>{{ $overdue->custody->due_at->format('d M Y') }}</dd></div><div><dt>Late fee rate</dt><dd>{{ $overdue->rate_snapshot === null ? 'Not configured' : 'PHP '.number_format((float)$overdue->rate_snapshot,2) }}</dd></div><div><dt>Accrued amount</dt><dd>{{ $overdue->rate_snapshot === null ? 'Not determined' : 'PHP '.number_format((float)$overdue->accrued_amount,2) }}</dd></div></dl>
<p class="meta">Late status begins on the calendar day after the Expected Return Date. Time of day is not used to determine lateness.</p>
@if($isOfficer && !in_array($overdue->status,['BILLED','RESOLVED'],true))
<form method="post" action="{{ route('overdue.bill',$overdue) }}" class="form-grid top-gap">@csrf<label>Billing basis<textarea name="basis" required placeholder="Use the configured client-approved late fee policy."></textarea></label><label>Payment due date <input type="date" name="due_at"></label><button class="button primary">Generate Billing Statement / Payment Assessment</button></form>
@endif
</article>
    @endforeach
</section>
@endif

@if($openIncidents->isNotEmpty())
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Property accountability</p>
            <h2>Property Accountability Cases</h2>
        </div>
    </div>
    @foreach($openIncidents as $incident)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $incident->incident_no }}</strong><h3>{{ str($incident->incident_type)->replace('_',' ')->title() }}</h3></div><x-status-badge :status="$incident->status" /></div><p>{{ $incident->remarks ?: 'No additional remarks.' }}</p>
<div class="table-wrap"><table><thead><tr><th>Qty</th><th>Finding</th><th>Disposition</th></tr></thead><tbody>@foreach($incident->lines as $line)<tr><td>{{ $line->quantity+0 }}</td><td>{{ str($line->observed_condition)->replace('_',' ')->title() }}</td><td>{{ str($line->disposition_state)->replace('_',' ')->title() }}</td></tr>@endforeach</tbody></table></div>
@if($isOfficer && !Illuminate\Support\Facades\DB::table('billing_lines')->where('incident_id',$incident->id)->exists() && !in_array($incident->status,['RESOLVED','CLOSED','VOID_CORRECTION'],true))
<form method="post" action="{{ route('incidents.bill',$incident) }}" class="form-grid top-gap">@csrf<div class="form-columns"><label>Accountability charge<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Payment due date<input type="date" name="due_at"></label></div><label>Assessment basis<textarea name="basis" required></textarea></label><button class="button primary">Generate Billing Statement</button></form>
@endif
</article>
    @endforeach
</section>
@endif

@if($openBillings->isNotEmpty())
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Cashier payment evidence</p>
            <h2>Open Billing Statements</h2>
        </div>
    </div>
    @foreach($openBillings as $billing)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $billing->billing_no }}</strong><h3>PHP {{ number_format((float)$billing->total_amount,2) }}</h3><small>{{ $billing->borrower->full_name }}</small></div><x-status-badge :status="$billing->status" /></div>
<div class="billing-lines">@foreach($billing->lines as $line)<p><strong>{{ str($line->line_type)->replace('_',' ')->title() }}</strong><span>{{ $line->description }}</span><small>PHP {{ number_format((float)$line->amount,2) }}</small></p>@endforeach</div>
<div class="actions">@foreach($billing->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)<a class="button secondary small" href="{{ route('documents.download',$document) }}">Download Billing Statement / Assessment</a>@endforeach</div>
<p class="meta">The system-generated document is not an Official Receipt. The borrower pays at the CSPC Cashier; SPMU then receives and uploads the paid Cashier receipt.</p>
@if($isOfficer && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))
<form method="post" action="{{ route('payments.store',$billing) }}" enctype="multipart/form-data" class="form-grid top-gap">@csrf<div class="card-header"><div><h4>Upload paid CSPC Cashier receipt</h4></div></div><div class="form-columns"><label>Cashier Receipt No.<input name="official_receipt_no" required></label><label>Receipt Date<input type="date" name="receipt_date" required></label><label>Amount Paid<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Scanned Paid Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label></div><label>Remarks<textarea name="remarks"></textarea></label><button class="button secondary">Upload Paid Receipt</button></form>
@endif
<div class="top-gap">
@forelse($billing->payments as $payment)
<div class="evidence-row"><div><x-status-badge :status="$payment->status" /><strong>{{ $payment->official_receipt_no }}</strong><small>{{ optional($payment->receipt_date)->format('d M Y') }} · PHP {{ number_format((float)$payment->amount,2) }}</small>@if($payment->evidence_file_id)<a class="table-action" href="{{ route('files.show', $payment->evidence_file_id, false) }}" target="_blank">View scanned Cashier receipt</a>@endif</div>
@if($isOfficer && $payment->status==='PENDING_VERIFICATION')<form method="post" action="{{ route('payments.verify',$payment) }}" class="form-grid">@csrf<label>Verification remarks<textarea name="remarks" required></textarea></label><div class="inline-actions"><button class="button primary small" name="decision" value="VERIFIED">Verify Paid</button><button class="button danger small" name="decision" value="REJECTED">Return for Correction</button></div></form>@endif</div>
@empty<p class="meta">No paid Cashier receipt uploaded.</p>@endforelse
</div>
@if($isHead && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))<details class="top-gap"><summary>Authorized billing waiver</summary><form method="post" action="{{ route('billings.waive',$billing) }}" class="form-grid top-gap">@csrf<label>Waiver reason<textarea name="reason" required></textarea></label><button class="button danger">Record Authorized Waiver</button></form></details>@endif
</article>
    @endforeach
</section>
@endif

@if($activeRestrictions->isNotEmpty())
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Borrowing eligibility</p>
                <h2>Active Restrictions</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Restriction</th><th>Reason</th><th>Effective</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($activeRestrictions as $restriction)
                        <tr>
                            <td>{{ str($restriction->restriction_type)->replace('_',' ')->title() }}</td>
                            <td>{{ $restriction->reason }}</td>
                            <td>{{ optional($restriction->effective_from)->format('d M Y') }}{{ $restriction->effective_to ? ' – '.$restriction->effective_to->format('d M Y') : ' until resolved' }}</td>
                            <td><x-status-badge :status="$restriction->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif

@if(($isHead || $workspace === 'BORROWER') && $sanctions->isNotEmpty())
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Administrative history</p>
                <h2>{{ $workspace === 'BORROWER' ? 'My Sanctions' : 'Sanction History' }}</h2>
                <p class="meta">Sanctions shown here are case decisions recorded by the SPMU Head. Financial charges remain separate under Billing Statements.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        @if($isHead)<th>Borrower</th>@endif
                        <th>Offense</th>
                        <th>Administrative Action</th>
                        <th>Academic Period</th>
                        <th>Effective</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sanctions as $sanction)
                        <tr>
                            @if($isHead)<td>{{ $sanction->borrower->full_name }}</td>@endif
                            <td>{{ $sanction->offense_no }}</td>
                            <td>
                                <strong>{{ $sanction->sanction_label }}</strong>
                                @if($sanction->remarks)<small>{{ $sanction->remarks }}</small>@endif
                            </td>
                            <td>{{ $sanction->academicPeriod?->academic_year }} {{ $sanction->academicPeriod?->term_name }}</td>
                            <td>
                                {{ optional($sanction->effective_from)->format('d M Y') }}
                                {{ $sanction->effective_to ? ' – '.$sanction->effective_to->format('d M Y') : '' }}
                            </td>
                            <td><x-status-badge :status="$sanction->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif
@endsection
