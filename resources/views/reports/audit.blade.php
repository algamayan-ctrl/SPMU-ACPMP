@extends('layouts.app', ['title' => 'Audit Trail'])
@section('content')
@php
    $groupedEvents = $events->groupBy(function ($event) {
        if ($event->occurred_at->isToday()) {
            return 'Today';
        }
        if ($event->occurred_at->isYesterday()) {
            return 'Yesterday';
        }
        return $event->occurred_at->format('d M Y');
    });
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Append-only operational evidence</p>
        <h1>Audit trail</h1>
        <p>Actor, action, record, time, origin, reason, and before/after values where applicable.</p>
    </div>
</section>

<section class="content-area">
    @if($events->isEmpty())
        <div class="card empty-state">No attributable administrative actions recorded.</div>
    @else
        <div class="audit-list">
            @foreach($groupedEvents as $dateLabel => $eventsForDay)
                <div class="audit-date-group">
                    <h3>{{ $dateLabel }}</h3>
                    @foreach($eventsForDay as $event)
                        @php
                            $actionLabel = match (strtolower((string) $event->action_code)) {
                                'user.updated' => 'Updated account',
                                'system_setting.updated' => 'Updated configuration',
                                'user.activated' => 'Activated account',
                                'user.deactivated' => 'Deactivated account',
                                'borrower_restriction.created' => 'Restricted borrowing',
                                'borrower_restriction.lifted' => 'Lifted restriction',
                                default => str_replace(['.', '_'], [' ', ' '], (string) $event->action_code),
                            };
                            $actorName = $event->actor?->full_name ?: 'System';
                            $recordName = class_basename($event->record_type) . ' #' . $event->record_id;
                        @endphp

                        <article class="audit-item">
                            <div class="audit-icon"><x-icon name="reports" size="16" /></div>
                            <div class="audit-content">
                                <div class="audit-main-row">
                                    <strong>{{ $actionLabel }}</strong>
                                    <span class="audit-time">{{ $event->occurred_at->format('g:i A') }}</span>
                                </div>
                                <div class="audit-meta-row">
                                    <span>{{ $actorName }}</span>
                                    <span>• {{ $recordName }}</span>
                                </div>
                                @if(filled($event->reason))
                                    <small>{{ $event->reason }}</small>
                                @endif
                                @if(filled($event->correlation_id))
                                    <small class="audit-correlation">Ref: {{ $event->correlation_id }}</small>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</section>
@endsection
