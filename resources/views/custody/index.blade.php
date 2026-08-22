@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Borrowings' : (auth()->user()?->access_classification?->value === 'SPMU_HEAD' ? 'Release & Return Oversight' : (($spmuMode ?? null) === 'return' ? 'Return' : 'Release'))])
@section('content')
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $isHead = auth()->user()?->access_classification?->value === 'SPMU_HEAD';
    $mode = $spmuMode ?? null;
    $pageTitle = $isBorrower
        ? 'My Borrowings'
        : ($isHead ? 'Release & Return Oversight' : ($mode === 'return' ? 'Return' : 'Release'));
    $pageCopy = match (true) {
        $isBorrower => 'Use My Borrowings for pickup schedules, items issued to you, outstanding returns, linen/laundry progress, and final reconciliation. Request approval and documents stay under My Requests.',
        $mode === 'release' => 'Schedule pickup, confirm item preparation, print the required physical documents, and record the actual handover.',
        $mode === 'return' => 'Inspect physically returned items, record full-quantity accounting, monitor linen/laundry return, and complete reconciliation.',
        default => null,
    };
@endphp
<section class="page-heading">
    <div>
        <p class="eyebrow">{{ $mode === 'return' ? 'Physical return and reconciliation' : ($mode === 'release' ? 'Pickup and physical issuance' : 'Pickup, issuance and return') }}</p>
        <h1>{{ $pageTitle }}</h1>
        @if($pageCopy)<p>{{ $pageCopy }}</p>@endif
    </div>
</section>
<section class="content-area">
<div class="operational-record-list">
@forelse($custodies as $custody)
    @php
        $outstanding = $custody->lines->sum(fn($line) => max(0, (float)$line->actual_released_quantity - (float)$line->returned_quantity));
        $scheduleDate = $custody->request->currentVersion?->schedule_date ?: $custody->request->currentVersion?->needed_from;
        $returnDate = $custody->request->currentVersion?->return_date ?: $custody->due_at;
        $hasActivePickupSchedule = (bool) $custody->scheduled_release_at
            && (bool) $custody->pickup_expires_at
            && ! $custody->pickup_expired_at;
        $operationalLabel = match (true) {
            $custody->status === 'CLOSED' => 'Completed',
            $custody->status === 'OBLIGATION_OPEN' => 'Obligation Open',
            $custody->status === 'RETURN_PROCESSING' => 'Return Processing',
            $custody->status === 'OVERDUE' => 'Overdue',
            (bool) $custody->released_at => 'Items Released / On Custody',
            (bool) $custody->prepared_at && $hasActivePickupSchedule => 'Ready for Release',
            $hasActivePickupSchedule => 'For Item Preparation',
            $custody->status === 'PREPARING_RELEASE' => 'For Pickup Scheduling',
            default => null,
        };
        $detailRoute = match ($mode) {
            'release' => route('custody.release.show', $custody),
            'return' => route('custody.return.show', $custody),
            default => route('custody.show', $custody),
        };
    @endphp
    <a class="operational-record ui-pressable" href="{{ $detailRoute }}">
        <span class="operational-record-primary"><strong>{{ $isBorrower ? $custody->custody_no : $custody->borrower->full_name }}</strong><span>Request {{ $custody->request->request_no }}</span><small>Schedule {{ optional($scheduleDate)->format('d M Y') }} · Return {{ optional($returnDate)->format('d M Y') }}</small></span>
        <span class="operational-record-facts">
            @if($mode === 'release')
                <span><small>Pickup</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span>
                <span><small>Preparation</small><strong>{{ $custody->prepared_at ? 'Confirmed' : 'Pending' }}</strong></span>
                <span><small>Issued</small><strong>Not yet</strong></span>
            @elseif($mode === 'return')
                <span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: '—' }}</strong></span>
                <span><small>Return Due</small><strong>{{ optional($returnDate)->format('d M Y') ?: '—' }}</strong></span>
                <span><small>Outstanding</small><strong>{{ $outstanding + 0 }}</strong></span>
            @else
                <span><small>Pickup</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span>
                <span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not yet' }}</strong></span>
                <span><small>Outstanding</small><strong>{{ $outstanding + 0 }}</strong></span>
            @endif
        </span>
        <span class="operational-record-action"><x-status-badge :status="$custody->status" :label="$operationalLabel" /><strong>View<x-icon name="chevron-right" size="16" /></strong></span>
    </a>
@empty
    <div class="empty-state">
        @if($mode === 'release')
            <strong>No transactions waiting for release.</strong>
            <span>Approved transactions appear here until physical release is confirmed.</span>
        @elseif($mode === 'return')
            <strong>No released transactions to return.</strong>
            <span>After physical release, the transaction moves here for return tracking and reconciliation.</span>
        @else
            <strong>No custody/pickup records.</strong>
            <span>A record appears after the SPMU Head verifies and approves a request and the approved quantities are allocated/held for pickup.</span>
        @endif
    </div>
@endforelse
</div>
</section>
@endsection
