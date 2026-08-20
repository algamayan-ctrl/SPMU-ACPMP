@extends('layouts.app', ['title' => 'Sanctions'])
@section('content')
@php
    $isSpmu = session('active_workspace') === 'SPMU';
@endphp
<section class="page-heading"><div><p class="eyebrow">Administrative compliance</p><h1>{{ $isSpmu ? 'Sanctions & Violations' : 'My Sanctions / Borrowing Status' }}</h1><p>Sanctions are separate from fees. Detected violations require SPMU review before an administrative sanction is confirmed.</p></div></section>
<section class="content-area"><article class="card"><div class="card-header"><div><p class="eyebrow">Violation review</p><h2>Detected violations</h2></div></div><div class="table-wrap"><table><thead><tr><th>Borrower</th><th>Transaction</th><th>Reasons</th><th>Academic Period</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($violations as $violation)
<tr><td>{{ $violation->borrower->full_name }}</td><td>{{ $violation->custody?->custody_no ?: '—' }}</td><td>{{ collect(data_get($violation->details_json,'reasons',[]))->map(fn($r)=>str($r)->replace('_',' ')->title())->join(', ') }}</td><td>{{ $violation->academicPeriod?->academic_year }} {{ $violation->academicPeriod?->term_name }}</td><td><x-status-badge :status="$violation->status" /></td><td>@if($isSpmu && $violation->status==='PENDING_REVIEW')<form method="post" action="{{ route('sanctions.review',$violation) }}" class="form-grid">@csrf<label>Remarks<textarea name="remarks"></textarea></label><div class="inline-actions"><button class="button primary small" name="decision" value="CONFIRMED">Confirm Violation</button><button class="button secondary small" name="decision" value="DISMISSED">Dismiss</button></div></form>@else—@endif</td></tr>
@empty<tr><td colspan="6">No violations recorded.</td></tr>@endforelse
</tbody></table></div></article></section>
<section class="content-area"><article class="card"><div class="card-header"><div><p class="eyebrow">History</p><h2>Confirmed sanctions</h2></div></div><div class="table-wrap"><table><thead><tr><th>Borrower</th><th>Offense</th><th>Sanction</th><th>Period</th><th>Effective</th><th>Status</th></tr></thead><tbody>
@forelse($sanctions as $sanction)<tr><td>{{ $sanction->borrower->full_name }}</td><td>{{ $sanction->offense_no }}</td><td>{{ $sanction->sanction_label }}</td><td>{{ $sanction->academicPeriod?->academic_year }} {{ $sanction->academicPeriod?->term_name }}</td><td>{{ optional($sanction->effective_from)->format('d M Y') }}{{ $sanction->effective_to ? ' to '.$sanction->effective_to->format('d M Y') : '' }}</td><td><x-status-badge :status="$sanction->status" /></td></tr>@empty<tr><td colspan="6">No sanctions confirmed.</td></tr>@endforelse
</tbody></table></div></article></section>
@endsection
