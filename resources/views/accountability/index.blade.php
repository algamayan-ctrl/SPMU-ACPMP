@extends('layouts.app', ['title' => 'Accountability, Billing & Fees'])
@section('content')
@php
    $workspace = session('active_workspace');
    $isSpmu = $workspace === 'SPMU';
    $activeRestrictions = $restrictions->where('status','ACTIVE');
@endphp
<section class="page-heading"><div><p class="eyebrow">Financial and property accountability</p><h1>{{ $isSpmu ? 'Accountability, Billing & Fees' : 'My Outstanding Obligations' }}</h1><p>Financial charges and administrative sanctions are tracked separately.</p></div></section>
@if(session('status'))<section class="content-area"><div class="callout success">{{ session('status') }}</div></section>@endif
@if($activeRestrictions->isNotEmpty())<section class="content-area"><div class="action-panel action-warning"><div><p class="eyebrow">Borrowing eligibility</p><h2>Borrowing is currently restricted</h2><p>Resolve the outstanding return, payment, accountability, or sanction-related restriction shown below before submitting another request.</p></div><x-status-badge status="ACTIVE" /></div></section>@endif

<section class="content-area"><div class="accountability-summary"><span><strong>{{ $overdueCases->whereNotIn('status',['RESOLVED'])->count() }}</strong> overdue</span><span><strong>{{ $incidents->whereNotIn('status',['RESOLVED','CLOSED','VOID_CORRECTION'])->count() }}</strong> accountability case(s)</span><span><strong>{{ $billings->whereNotIn('status',['SETTLED','WAIVED','VOID'])->count() }}</strong> open billing(s)</span><span><strong>{{ $activeRestrictions->count() }}</strong> active restriction(s)</span></div></section>

<section class="content-area"><div class="section-heading"><div><p class="eyebrow">Date-based lateness</p><h2>Overdue Cases</h2></div></div>
@forelse($overdueCases as $overdue)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $overdue->custody->custody_no }}</strong><h3>{{ $overdue->borrower->full_name }}</h3></div><x-status-badge :status="$overdue->status" /></div>
<dl class="summary-grid compact"><div><dt>Expected Return Date</dt><dd>{{ $overdue->custody->due_at->format('d M Y') }}</dd></div><div><dt>Late fee rate</dt><dd>{{ $overdue->rate_snapshot === null ? 'Not configured' : 'PHP '.number_format((float)$overdue->rate_snapshot,2) }}</dd></div><div><dt>Accrued amount</dt><dd>{{ $overdue->rate_snapshot === null ? 'Not determined' : 'PHP '.number_format((float)$overdue->accrued_amount,2) }}</dd></div></dl>
<p class="meta">Late status begins on the calendar day after the Expected Return Date. Time of day is not used to determine lateness.</p>
@if($isSpmu && !in_array($overdue->status,['BILLED','RESOLVED'],true))
<form method="post" action="{{ route('overdue.bill',$overdue) }}" class="form-grid top-gap">@csrf<label>Billing basis<textarea name="basis" required placeholder="Use the configured client-approved late fee policy."></textarea></label><label>Payment due date <input type="date" name="due_at"></label><button class="button primary">Generate Billing Statement / Payment Assessment</button></form>
@endif
</article>
@empty<div class="empty-state"><strong>No overdue cases.</strong></div>@endforelse
</section>

<section class="content-area"><div class="section-heading"><div><p class="eyebrow">SLDDP / property accountability</p><h2>Accountability Cases</h2></div></div>
@forelse($incidents as $incident)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $incident->incident_no }}</strong><h3>{{ str($incident->incident_type)->replace('_',' ')->title() }}</h3></div><x-status-badge :status="$incident->status" /></div><p>{{ $incident->remarks ?: 'No additional remarks.' }}</p>
<div class="table-wrap"><table><thead><tr><th>Qty</th><th>Finding</th><th>Disposition</th></tr></thead><tbody>@foreach($incident->lines as $line)<tr><td>{{ $line->quantity+0 }}</td><td>{{ str($line->observed_condition)->replace('_',' ')->title() }}</td><td>{{ str($line->disposition_state)->replace('_',' ')->title() }}</td></tr>@endforeach</tbody></table></div>
@if($isSpmu && !Illuminate\Support\Facades\DB::table('billing_lines')->where('incident_id',$incident->id)->exists() && !in_array($incident->status,['RESOLVED','CLOSED','VOID_CORRECTION'],true))
<form method="post" action="{{ route('incidents.bill',$incident) }}" class="form-grid top-gap">@csrf<div class="form-columns"><label>Configurable accountability charge<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Payment due date<input type="date" name="due_at"></label></div><label>Assessment basis<textarea name="basis" required></textarea></label><button class="button primary">Generate Billing Statement</button></form>
@endif
</article>
@empty<div class="empty-state"><strong>No accountability cases.</strong></div>@endforelse
</section>

<section class="content-area"><div class="section-heading"><div><p class="eyebrow">Cashier payment evidence</p><h2>Billing Statements</h2></div></div>
@forelse($billings as $billing)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $billing->billing_no }}</strong><h3>PHP {{ number_format((float)$billing->total_amount,2) }}</h3><small>{{ $billing->borrower->full_name }}</small></div><x-status-badge :status="$billing->status" /></div>
<div class="billing-lines">@foreach($billing->lines as $line)<p><strong>{{ str($line->line_type)->replace('_',' ')->title() }}</strong><span>{{ $line->description }}</span><small>PHP {{ number_format((float)$line->amount,2) }}</small></p>@endforeach</div>
<div class="actions">@foreach($billing->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)<a class="button secondary small" href="{{ route('documents.download',$document) }}">Download Billing Statement / Assessment</a>@endforeach</div>
<p class="meta">The system-generated document is not an Official Receipt. The borrower pays at the CSPC Cashier; SPMU then receives and uploads the paid Cashier receipt.</p>
@if($isSpmu && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))
<form method="post" action="{{ route('payments.store',$billing) }}" enctype="multipart/form-data" class="form-grid top-gap">@csrf<div class="card-header"><div><h4>Upload paid CSPC Cashier receipt</h4></div></div><div class="form-columns"><label>Cashier Receipt No.<input name="official_receipt_no" required></label><label>Receipt Date<input type="date" name="receipt_date" required></label><label>Amount Paid<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Scanned Paid Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label></div><label>Remarks<textarea name="remarks"></textarea></label><button class="button secondary">Upload Paid Receipt</button></form>
@endif
<div class="top-gap">
@forelse($billing->payments as $payment)
<div class="evidence-row"><div><x-status-badge :status="$payment->status" /><strong>{{ $payment->official_receipt_no }}</strong><small>{{ optional($payment->receipt_date)->format('d M Y') }} · PHP {{ number_format((float)$payment->amount,2) }}</small>@if($payment->evidence_file_id)<a class="table-action" href="{{ route('files.show', $payment->evidence_file_id, false) }}" target="_blank">View scanned Cashier receipt</a>@endif</div>
@if($isSpmu && $payment->status==='PENDING_VERIFICATION')<form method="post" action="{{ route('payments.verify',$payment) }}" class="form-grid">@csrf<label>Verification remarks<textarea name="remarks" required></textarea></label><div class="inline-actions"><button class="button primary small" name="decision" value="VERIFIED">Verify Paid</button><button class="button danger small" name="decision" value="REJECTED">Return for Correction</button></div></form>@endif</div>
@empty<p class="meta">No paid Cashier receipt uploaded.</p>@endforelse
</div>
@if($isSpmu && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))<details class="top-gap"><summary>Authorized billing waiver</summary><form method="post" action="{{ route('billings.waive',$billing) }}" class="form-grid top-gap">@csrf<label>Waiver reason<textarea name="reason" required></textarea></label><button class="button danger">Record Authorized Waiver</button></form></details>@endif
</article>
@empty<div class="empty-state"><strong>No billing statements.</strong></div>@endforelse
</section>

<section class="content-area"><article class="card"><div class="card-header"><div><p class="eyebrow">Borrowing eligibility</p><h2>Restrictions</h2></div></div><div class="table-wrap"><table><thead><tr><th>Restriction</th><th>Reason</th><th>Effective</th><th>Status</th></tr></thead><tbody>@forelse($restrictions as $restriction)<tr><td>{{ str($restriction->restriction_type)->replace('_',' ')->title() }}</td><td>{{ $restriction->reason }}</td><td>{{ optional($restriction->effective_from)->format('d M Y') }}{{ $restriction->effective_to ? ' – '.$restriction->effective_to->format('d M Y') : ' until resolved' }}</td><td><x-status-badge :status="$restriction->status" /></td></tr>@empty<tr><td colspan="4">No restrictions.</td></tr>@endforelse</tbody></table></div></article></section>
@endsection
