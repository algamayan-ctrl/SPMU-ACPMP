{{-- LAUNDRY UNIFORM DASHBOARD V1.1 20260822 --}}
@extends('layouts.app', ['title' => 'Dashboard'])

@section('content')
@php
    $firstName = explode(
        ' ',
        trim((string) auth()->user()->full_name)
    )[0] ?: 'Laundry Worker';
@endphp

{{-- ========================================================= --}}
{{-- PAGE HEADING                                              --}}
{{-- ========================================================= --}}

<section class="page-heading dashboard-heading">
    <div>
        <p class="eyebrow">Overview</p>

        <h1>Welcome, {{ $firstName }}</h1>

        <p>
            Track laundry transactions, completed forms, borrower pickups,
            and finished work in one place.
        </p>
    </div>

    <a
        class="button primary ui-pressable"
        href="{{ route('laundry.index', ['tab' => 'needs-action']) }}"
    >
        <x-icon name="custody" size="16" />
        View transactions
    </a>
</section>


{{-- ========================================================= --}}
{{-- STATISTIC CARDS                                           --}}
{{-- ========================================================= --}}

<section class="stat-grid" aria-label="Current laundry totals">
    <a
        class="card stat-card stat-card-link kpi-card dashboard-kpi-card kpi-accent-info ui-pressable"
        href="{{ route('laundry.index', ['tab' => 'needs-action']) }}"
    >
        <span class="kpi-icon" aria-hidden="true">
            <x-icon name="requests" size="18" />
        </span>

        <strong class="kpi-value">
            {{ number_format($statistics['needs_action']) }}
        </strong>

        <span class="kpi-label">Needs action</span>

        <span class="stat-card-arrow" aria-hidden="true">
            <x-icon name="chevron-right" />
        </span>
    </a>

    <a
        class="card stat-card stat-card-link kpi-card dashboard-kpi-card kpi-accent-warning ui-pressable"
        href="{{ route('laundry.index', ['tab' => 'needs-action']) }}"
    >
        <span class="kpi-icon" aria-hidden="true">
            <x-icon name="custody" size="18" />
        </span>

        <strong class="kpi-value">
            {{ number_format($statistics['ready_for_pickup']) }}
        </strong>

        <span class="kpi-label">Ready for pickup</span>

        <span class="stat-card-arrow" aria-hidden="true">
            <x-icon name="chevron-right" />
        </span>
    </a>

    <a
        class="card stat-card stat-card-link kpi-card dashboard-kpi-card kpi-accent-success ui-pressable"
        href="{{ route('laundry.index', ['tab' => 'completed']) }}"
    >
        <span class="kpi-icon" aria-hidden="true">
            <x-icon name="success" size="18" />
        </span>

        <strong class="kpi-value">
            {{ number_format($statistics['completed']) }}
        </strong>

        <span class="kpi-label">Completed</span>

        <span class="stat-card-arrow" aria-hidden="true">
            <x-icon name="chevron-right" />
        </span>
    </a>
</section>


{{-- ========================================================= --}}
{{-- DASHBOARD MAIN GRID                                       --}}
{{-- ========================================================= --}}

<section class="dashboard-grid">
    <article class="card queue-card attention-card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Your task list</p>
                <h2>What needs your attention</h2>
            </div>

            <a
                class="dashboard-view-all"
                href="{{ route('laundry.index', ['tab' => 'needs-action']) }}"
            >
                View all
                <x-icon name="chevron-right" size="16" />
            </a>
        </div>

        <div class="queue-list">
            @forelse($actionJobs as $job)
                @php
                    [$nextStep, $actionLabel] = match ($job->status) {
                        'FORM_REPLACEMENT_REQUIRED' => [
                            'SPMU requested a replacement. Upload a clear and readable accomplished Laundry Form.',
                            'Replace form scan',
                        ],
                        'FOR_LAUNDRY' => [
                            'Continue the physical Laundry Form and laundry process for these linen items.',
                            'Continue laundry',
                        ],
                        'RECEIVED_IN_LAUNDRY' => [
                            'The used linen was received. Complete the laundry work and upload the accomplished form.',
                            'Complete laundry',
                        ],
                        'READY_FOR_PICKUP' => [
                            'The accomplished form was uploaded. Release the cleaned linen only when the borrower collects it.',
                            'Process pickup',
                        ],
                        default => [
                            'Open this transaction to review its current status and required next step.',
                            'View transaction',
                        ],
                    };

                    $lineCount = $job->lines->count();
                    $lineLabel = $lineCount === 1 ? 'item line' : 'item lines';
                @endphp

                <article class="dashboard-task-row">
                    <a
                        class="dashboard-task-link ui-pressable"
                        href="{{ route('laundry.show', $job) }}"
                    >
                        <span class="dashboard-task-copy">
                            <span class="dashboard-task-purpose">
                                {{ $job->custody->custody_no }}
                            </span>

                            <span class="dashboard-task-heading">
                                <span class="record-reference">
                                    {{ $job->custody->request->request_no }}
                                </span>

                                <x-status-badge :status="$job->status" />
                            </span>

                            <small>
                                Borrower: {{ $job->custody->borrower->full_name }}
                                &middot; {{ $lineCount }} {{ $lineLabel }}
                            </small>

                            <small>{{ $nextStep }}</small>

                            <span class="dashboard-task-footer">
                                <span class="dashboard-task-date">
                                    Updated {{ optional($job->updated_at)->format('d M Y') }}
                                </span>

                                <span class="dashboard-task-action">
                                    {{ $actionLabel }}
                                </span>
                            </span>
                        </span>

                        <x-icon name="chevron-right" />
                    </a>
                </article>
            @empty
                <div class="empty-state">
                    <div>
                        <strong>You are up to date</strong>
                        <br>
                        <span>Nothing requires your attention right now.</span>
                    </div>
                </div>
            @endforelse
        </div>
    </article>


    {{-- ===================================================== --}}
    {{-- QUICK ACTIONS                                         --}}
    {{-- ===================================================== --}}

    <aside class="card quick-card">
        <p class="eyebrow">Shortcuts</p>
        <h2>Common tasks</h2>

        <nav class="quick-actions" aria-label="Laundry quick actions">
            <a
                class="interactive ui-pressable"
                href="{{ route('laundry.index', ['tab' => 'needs-action']) }}"
            >
                <span class="quick-action-icon" aria-hidden="true">
                    <x-icon name="requests" size="18" />
                </span>

                <span>
                    <strong>Open laundry transactions</strong>
                    <small>Continue forms, laundry work, and borrower pickup actions.</small>
                </span>

                <x-icon name="chevron-right" />
            </a>

            <a
                class="interactive ui-pressable"
                href="{{ route('laundry.index', ['tab' => 'completed']) }}"
            >
                <span class="quick-action-icon" aria-hidden="true">
                    <x-icon name="success" size="18" />
                </span>

                <span>
                    <strong>View completed transactions</strong>
                    <small>Review linen released to borrowers and finalized work.</small>
                </span>

                <x-icon name="chevron-right" />
            </a>

            <a
                class="interactive ui-pressable"
                href="{{ route('notifications.index') }}"
            >
                <span class="quick-action-icon" aria-hidden="true">
                    <x-icon name="notifications" size="18" />
                </span>

                <span>
                    <strong>View notifications</strong>
                    <small>Check new laundry assignments and workflow updates.</small>
                </span>

                <x-icon name="chevron-right" />
            </a>
        </nav>
    </aside>
</section>
@endsection