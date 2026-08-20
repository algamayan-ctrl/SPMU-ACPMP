@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Borrowings' : 'Pickup & Custody'])
@section('content')
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
@endphp
<section class="page-heading"><div><p class="eyebrow">Pickup, issuance and return</p><h1>{{ $isBorrower ? 'My Borrowings' : 'Pickup & Custody' }}</h1></div></section>
<section class="content-area">
<div class="operational-record-list">
@forelse($custodies as $custody)
    @php
        $outstanding = $custody->lines->sum(fn($line) => max(0, (float)$line->actual_released_quantity - (float)$line->returned_quantity));
        $scheduleDate = $custody->request->currentVersion?->schedule_date ?: $custody->request->currentVersion?->needed_from;
        $returnDate = $custody->request->currentVersion?->return_date ?: $custody->due_at;
    @endphp
    <a class="operational-record ui-pressable" href="{{ route('custody.show', $custody) }}">
        <span class="operational-record-primary"><strong>{{ $isBorrower ? $custody->custody_no : $custody->borrower->full_name }}</strong><span>Request {{ $custody->request->request_no }}</span><small>Schedule {{ optional($scheduleDate)->format('d M Y') }} · Return {{ optional($returnDate)->format('d M Y') }}</small></span>
        <span class="operational-record-facts"><span><small>Pickup</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span><span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not yet' }}</strong></span><span><small>Outstanding</small><strong>{{ $outstanding + 0 }}</strong></span></span>
        <span class="operational-record-action"><x-status-badge :status="$custody->status" /><strong>View<x-icon name="chevron-right" size="16" /></strong></span>
    </a>
@empty
    <div class="empty-state"><strong>No custody/pickup records.</strong><span>A record appears after SPMU approves and reserves a request.</span></div>
@endforelse
</div>
</section>
@endsection
