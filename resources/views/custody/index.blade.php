@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Borrowings' : 'Custody'])

@section('content')

@php
    $isBorrower = session('active_workspace') === 'BORROWER';

    if ($isBorrower) {
        $preparingCount = $custodies
            ->where('status', 'PREPARING_RELEASE')
            ->count();

        $onCustodyCount = $custodies
            ->whereIn(
                'status',
                [
                    'ACTIVE',
                    'PARTIALLY_RETURNED',
                    'EARLY_RETURN',
                ]
            )
            ->count();

        $overdueCount = $custodies
            ->where('status', 'OVERDUE')
            ->count();

        $completedCount = $custodies
            ->where('status', 'CLOSED')
            ->count();
    }
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Release and return</p>

        <h1>
            {{ $isBorrower ? 'My Borrowings' : 'Borrower Slips and custody' }}
        </h1>
    </div>
</section>

<section class="content-area">

@if($isBorrower)

    {{-- ====================================================== --}}
    {{-- BORROWER SUMMARY                                      --}}
    {{-- ====================================================== --}}

    <div
        class="accountability-summary operational-summary"
        aria-label="My borrowings summary"
    >
        <span>
            <strong>{{ $preparingCount }}</strong>
            Preparing
        </span>

        <span>
            <strong>{{ $onCustodyCount }}</strong>
            On Custody
        </span>

        <span>
            <strong>{{ $overdueCount }}</strong>
            Overdue
        </span>

        <span>
            <strong>{{ $completedCount }}</strong>
            Returned
        </span>
    </div>

    {{-- ====================================================== --}}
    {{-- BORROWER BORROWING LIST                               --}}
    {{-- ====================================================== --}}

    <div
        class="borrowing-list"
        aria-label="My borrowings"
    >

        @forelse($custodies as $custody)

            @php
                $outstanding = $custody->lines->sum(
                    fn ($line) =>
                        max(
                            0,
                            (float) $line->actual_released_quantity
                            - (float) $line->returned_quantity
                        )
                );

                $itemNames = $custody->lines
                    ->pluck(
                        'requestItem.inventoryItem.unique_description'
                    )
                    ->filter()
                    ->take(2)
                    ->join(', ');

                $extraItems = max(
                    0,
                    $custody->lines->count() - 2
                );

                $guidance = match($custody->status) {
                    'PREPARING_RELEASE' =>
                        'SPMU is preparing the approved items for physical release.',

                    'ACTIVE' =>
                        'Items are currently under your custody. Return them by the stated date.',

                    'PARTIALLY_RETURNED' =>
                        'Some issued quantities have been returned. Outstanding quantities remain under your custody.',

                    'OVERDUE' =>
                        'Return is overdue. Coordinate the physical return with SPMU immediately.',

                    'EARLY_RETURN' =>
                        'Your early-return notice is recorded and awaiting physical handover and inspection.',

                    'INCIDENT_OPEN',
                    'OBLIGATION_OPEN' =>
                        'An accountability obligation remains open. Review the borrowing details.',

                    'CLOSED' =>
                        'This borrowing has been completed and all recorded return requirements are closed.',

                    default =>
                        'Open this borrowing to review its latest release and return details.',
                };

                $actionLabel = match($custody->status) {
                    'PREPARING_RELEASE' =>
                        'View release status',

                    'ACTIVE' =>
                        'View borrowing',

                    'PARTIALLY_RETURNED' =>
                        'View remaining items',

                    'OVERDUE' =>
                        'Review overdue borrowing',

                    'EARLY_RETURN' =>
                        'View return status',

                    'INCIDENT_OPEN',
                    'OBLIGATION_OPEN' =>
                        'Review obligation',

                    'CLOSED' =>
                        'View completed borrowing',

                    default =>
                        'View borrowing',
                };
            @endphp

            <a
                class="borrowing-list-item ui-pressable"
                href="{{ route('custody.show', $custody) }}"
            >
                <span class="borrowing-list-main">

                    <span class="request-list-heading">

                        <strong>
                            {{ $custody->custody_no }}
                        </strong>

                        <x-status-badge
                            :status="$custody->status"
                        />

                    </span>

                    <span>
                        {{
                            $itemNames
                                ?: $custody->request->request_no
                        }}

                        @if($extraItems)
                            +{{ $extraItems }} more
                        @endif
                    </span>

                    <small>
                        {{ $guidance }}
                    </small>

                </span>

                <span class="borrowing-list-meta">

                    <span>
                        Request
                        {{ $custody->request->request_no }}
                    </span>

                    <small>
                        Released:
                        {{
                            optional($custody->released_at)
                                ->format('d M Y')
                                ?: 'Not yet released'
                        }}
                    </small>

                    <small>
                        Return due:
                        {{ $custody->due_at->format('d M Y') }}
                    </small>

                    <small>
                        {{ $outstanding + 0 }}
                        outstanding unit(s)
                    </small>

                </span>

                <span class="request-list-action">
                    {{ $actionLabel }}

                    <x-icon name="chevron-right" />
                </span>

            </a>

        @empty

            <div class="empty-state borrower-empty-state">

                <div>
                    <strong>
                        No borrowing records yet.
                    </strong>

                    <span>
                        A borrowing record will appear here after
                        SPMU approves a request and prepares the
                        approved items for release.
                    </span>
                </div>

                <a
                    class="button secondary ui-pressable"
                    href="{{ route('requests.index') }}"
                >
                    View my requests
                </a>

            </div>

        @endforelse

    </div>

@else

    {{-- ====================================================== --}}
    {{-- EXISTING SPMU / OPERATIONAL VIEW                      --}}
    {{-- ====================================================== --}}

    @php
        $preparingCount = $custodies
            ->where('status', 'PREPARING_RELEASE')
            ->count();

        $onCustodyCount = $custodies
            ->whereIn(
                'status',
                [
                    'ACTIVE',
                    'PARTIALLY_RETURNED',
                    'EARLY_RETURN',
                ]
            )
            ->count();

        $overdueCount = $custodies
            ->where('status', 'OVERDUE')
            ->count();

        $obligationCount = $custodies
            ->whereIn(
                'status',
                [
                    'INCIDENT_OPEN',
                    'OBLIGATION_OPEN',
                ]
            )
            ->count();
    @endphp

    <div
        class="accountability-summary operational-summary"
        aria-label="Release and return summary"
    >
        <span>
            <strong>{{ $preparingCount }}</strong>
            preparing for release
        </span>

        <span>
            <strong>{{ $onCustodyCount }}</strong>
            on custody
        </span>

        <span>
            <strong>{{ $overdueCount }}</strong>
            overdue
        </span>

        <span>
            <strong>{{ $obligationCount }}</strong>
            open obligation(s)
        </span>
    </div>

    <div
        class="operational-record-list custody-operations-list"
        aria-label="Custody records"
    >

        @forelse($custodies as $custody)

            @php
                $outstanding = $custody->lines->sum(
                    fn ($line) =>
                        max(
                            0,
                            (float) $line->actual_released_quantity
                            - (float) $line->returned_quantity
                        )
                );

                $itemNames = $custody->lines
                    ->pluck(
                        'requestItem.inventoryItem.unique_description'
                    )
                    ->filter()
                    ->take(2)
                    ->join(', ');

                $extraItems = max(
                    0,
                    $custody->lines->count() - 2
                );

                $actionLabel = match($custody->status) {
                    'PREPARING_RELEASE' =>
                        $custody->prepared_at
                            ? (
                                $custody->acknowledged_at
                                    ? 'Record release'
                                    : 'Await acknowledgement'
                            )
                            : 'Prepare release',

                    'ACTIVE',
                    'OVERDUE',
                    'EARLY_RETURN' =>
                        'Receive return',

                    'PARTIALLY_RETURNED' =>
                        'Continue return',

                    'INCIDENT_OPEN',
                    'OBLIGATION_OPEN' =>
                        'Review obligation',

                    default =>
                        'View custody record',
                };

                $tone =
                    $custody->status === 'OVERDUE'
                        ? 'is-danger'
                        : (
                            in_array(
                                $custody->status,
                                [
                                    'PREPARING_RELEASE',
                                    'PARTIALLY_RETURNED',
                                    'EARLY_RETURN',
                                    'INCIDENT_OPEN',
                                    'OBLIGATION_OPEN',
                                ],
                                true
                            )
                                ? 'is-warning'
                                : 'is-info'
                        );
            @endphp

            <a
                class="operational-record ui-pressable {{ $tone }}"
                href="{{ route('custody.show', $custody) }}"
            >
                <span class="operational-record-primary">

                    <strong>
                        {{ $custody->borrower->full_name }}
                    </strong>

                    <span>
                        {{
                            $itemNames
                                ?: 'Approved institutional property'
                        }}

                        @if($extraItems)
                            +{{ $extraItems }} more
                        @endif
                    </span>

                    <small>
                        {{ $custody->custody_no }}
                        &middot;
                        Request {{ $custody->request->request_no }}
                    </small>

                </span>

                <span class="operational-record-facts">

                    <span>
                        <small>Released</small>

                        <strong>
                            <x-date
                                :value="$custody->released_at"
                                with-time
                                fallback="Not yet released"
                            />
                        </strong>
                    </span>

                    <span>
                        <small>Return deadline</small>

                        <strong>
                            <x-date
                                :value="$custody->due_at"
                                with-time
                            />
                        </strong>
                    </span>

                    <span>
                        <small>Outstanding</small>

                        <strong>
                            {{ $outstanding + 0 }}
                            unit(s)
                        </strong>
                    </span>

                </span>

                <span class="operational-record-action">

                    <x-status-badge
                        :status="$custody->status"
                    />

                    <strong>
                        {{ $actionLabel }}

                        <x-icon
                            name="chevron-right"
                            size="16"
                        />
                    </strong>

                </span>
            </a>

        @empty

            <div class="empty-state borrower-empty-state">
                <div>
                    <strong>No custody records.</strong>

                    <span>
                        A custody record appears after an approved
                        request proceeds to release preparation.
                    </span>
                </div>
            </div>

        @endforelse

    </div>

@endif

</section>

@endsection
