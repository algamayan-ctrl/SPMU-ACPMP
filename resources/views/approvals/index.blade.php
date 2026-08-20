@extends('layouts.app', ['title' => 'SPMU Review Queue'])

@section('content')

@php
    $isSpmuStage = strtoupper((string) $stage) === 'SPMU';
@endphp

<section class="page-heading approval-queue-heading">
    <div>
        <p class="eyebrow">
            {{ $isSpmuStage ? 'Borrowing request review' : $stage.' review' }}
        </p>

        <h1>
            {{ $isSpmuStage ? 'SPMU Review Queue' : 'Approval Queue' }}
        </h1>

        @if($isSpmuStage)
            <p>
                Review submitted borrowing requests before recording the SPMU decision.
            </p>
        @endif
    </div>

</section>

<section class="content-area">

    <div
        class="approval-queue-list"
        aria-label="Requests awaiting {{ $stage }} review"
    >
        @forelse($requests as $request)

            @php
                $version = $request->currentVersion;
                $submittedAt = $version->submitted_at ?: $request->updated_at;
                $itemCount = $version->items->count();
            @endphp

            <a
                class="approval-queue-item ui-pressable"
                href="{{ route('requests.show', $request) }}"
            >

                <span class="approval-queue-primary">

                    <strong>
                        {{ $version->purpose_event ?: 'Borrowing request' }}
                    </strong>

                    <span class="approval-queue-borrower">
                        {{ $request->borrower->full_name }}
                    </span>

                    <span class="record-reference">
                        {{ $request->request_no }}
                    </span>

                </span>

                <span class="approval-queue-schedule">

                    <span class="approval-queue-period">
                        <span>
                            <small>Items needed from</small>
                            <strong>
                                <x-date :value="$version->needed_from" />
                            </strong>
                        </span>

                        <span aria-hidden="true">&rarr;</span>

                        <span>
                            <small>Expected return</small>
                            <strong>
                                <x-date :value="$version->return_due_at" />
                            </strong>
                        </span>
                    </span>

                    <small>
                        Submitted
                        <x-date :value="$submittedAt" with-time />
                    </small>

                </span>

                <span class="approval-queue-indicators">

                    <span>
                        {{ $itemCount }}
                        {{ \Illuminate\Support\Str::plural('item type', $itemCount) }}
                    </span>

                    @if($version->off_campus)
                        <span class="context-chip context-chip-warning">
                            Off-campus
                        </span>
                    @else
                        <span class="context-chip">
                            On-campus
                        </span>
                    @endif

                    <x-status-badge :status="$request->status" />

                </span>

                <span class="approval-queue-action">
                    Review request
                    <x-icon name="chevron-right" size="17" />
                </span>

            </a>

        @empty

            <div class="empty-state approval-queue-empty">
                <strong>
                    {{
                        $isSpmuStage
                            ? 'No requests awaiting SPMU review.'
                            : 'No requests awaiting '.$stage.' review.'
                    }}
                </strong>

                @if($isSpmuStage)
                    <span>
                        Newly submitted requests will appear here when they are ready for SPMU review.
                    </span>
                @endif
            </div>

        @endforelse
    </div>

</section>

@endsection
