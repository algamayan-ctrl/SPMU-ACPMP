@extends('layouts.app', ['title' => 'Approval Queue'])

@section('content')
<section class="page-heading approval-queue-heading">
    <div>
        <p class="eyebrow">{{ $stage }} review</p>
        <h1>Approval Queue</h1>
    </div>
    @unless($canDecide)
        <span class="context-chip">Review-only access</span>
    @endunless
</section>

<section class="content-area">
    <div class="approval-queue-list" aria-label="Requests awaiting {{ $stage }} review">
        @forelse($requests as $request)
            @php
                $version = $request->currentVersion;
                $submittedAt = $version->submitted_at ?: $request->updated_at;
            @endphp
            <a class="approval-queue-item ui-pressable" href="{{ route('requests.show', $request) }}">
                <span class="approval-queue-primary">
                    <strong>{{ $version->purpose_event ?: $request->request_no }}</strong>
                    <span class="approval-queue-borrower">{{ $request->borrower->full_name }}</span>
                    <span class="record-reference">{{ $request->request_no }}</span>
                </span>
                <span class="approval-queue-schedule">
                    <span class="approval-queue-period"><x-date :value="$version->needed_from" /> <span aria-hidden="true">→</span> <x-date :value="$version->return_due_at" /></span>
                    <small>Submitted <x-date :value="$submittedAt" with-time /></small>
                </span>
                <span class="approval-queue-indicators">
                    <span>{{ $version->items->count() }} item type(s)</span>
                    @if($version->off_campus)<span class="context-chip context-chip-warning">Off-campus</span>@endif
                    <x-status-badge :status="$request->status" />
                </span>
                <span class="approval-queue-action">Review request<x-icon name="chevron-right" size="17" /></span>
            </a>
        @empty
            <div class="empty-state approval-queue-empty">
                <strong>No requests awaiting {{ $stage }} review.</strong>
            </div>
        @endforelse
    </div>
</section>
@endsection
