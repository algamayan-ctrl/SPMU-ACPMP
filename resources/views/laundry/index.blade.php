{{-- LAUNDRY WORKSPACE UI V1 20260822 --}}
@extends('layouts.app', ['title' => 'Laundry Transactions'])

@section('content')
@php
    $activeTab = $tab ?? 'needs-action';
    $isCompletedTab = $activeTab === 'completed';
@endphp

<style>
    .laundry-workspace {
        --laundry-blue: #1268df;
        --laundry-blue-dark: #0a4fae;
        --laundry-navy: #07254a;
        --laundry-border: #d3dfed;
        --laundry-muted: #607493;
        display: grid;
        gap: 18px;
    }

    .laundry-workspace .laundry-page-heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 20px;
        margin: 0;
    }

    .laundry-workspace .laundry-page-heading h1 {
        margin: 4px 0 4px;
        color: var(--laundry-navy);
        font-size: clamp(1.55rem, 2.4vw, 2rem);
    }

    .laundry-workspace .laundry-page-heading p:last-child {
        margin: 0;
        color: var(--laundry-muted);
    }

    .laundry-tabs {
        display: inline-flex;
        width: fit-content;
        gap: 6px;
        padding: 5px;
        border: 1px solid var(--laundry-border);
        border-radius: 12px;
        background: #edf3f9;
    }

    .laundry-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 40px;
        padding: 0 16px;
        border-radius: 8px;
        color: #435873;
        text-decoration: none;
        font-weight: 750;
    }

    .laundry-tab:hover {
        color: var(--laundry-blue-dark);
        background: rgba(255, 255, 255, .7);
        text-decoration: none;
    }

    .laundry-tab.active {
        color: #fff;
        background: var(--laundry-blue);
        box-shadow: 0 5px 13px rgba(18, 104, 223, .2);
    }

    .laundry-tab-count {
        min-width: 24px;
        padding: 2px 7px;
        border-radius: 999px;
        background: rgba(7, 37, 74, .09);
        text-align: center;
        font-size: .72rem;
    }

    .laundry-tab.active .laundry-tab-count {
        background: rgba(255, 255, 255, .2);
    }

    .laundry-panel {
        overflow: hidden;
        border: 1px solid var(--laundry-border);
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 2px 5px rgba(14, 48, 86, .04);
    }

    .laundry-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        border-bottom: 1px solid var(--laundry-border);
        background: #fbfdff;
    }

    .laundry-panel-header h2 {
        margin: 3px 0 0;
        color: var(--laundry-navy);
        font-size: 1.08rem;
    }

    .laundry-panel-header .meta {
        margin: 0;
    }

    .laundry-transaction-list {
        display: grid;
    }

    .laundry-transaction-row {
        display: grid;
        grid-template-columns: minmax(230px, 1.5fr) minmax(165px, .9fr) minmax(180px, .9fr) auto;
        align-items: center;
        gap: 18px;
        min-height: 96px;
        padding: 16px 20px;
        border-bottom: 1px solid #e3ebf4;
    }

    .laundry-transaction-row:last-child {
        border-bottom: 0;
    }

    .laundry-transaction-row:hover {
        background: #f8fbff;
    }

    .laundry-primary strong,
    .laundry-cell strong {
        display: block;
        color: var(--laundry-navy);
    }

    .laundry-primary small,
    .laundry-cell small {
        display: block;
        margin-top: 4px;
        color: var(--laundry-muted);
        line-height: 1.4;
    }

    .laundry-action-copy {
        color: #274766;
        font-weight: 700;
    }

    .laundry-row-action {
        min-width: 112px;
        justify-content: center;
    }

    .laundry-empty {
        display: grid;
        justify-items: center;
        gap: 7px;
        padding: 48px 24px;
        color: var(--laundry-muted);
        text-align: center;
    }

    .laundry-empty strong {
        color: var(--laundry-navy);
        font-size: 1rem;
    }

    .laundry-pagination {
        padding: 14px 20px;
        border-top: 1px solid var(--laundry-border);
    }

    @media (max-width: 920px) {
        .laundry-transaction-row {
            grid-template-columns: 1fr 1fr;
        }

        .laundry-row-action {
            width: 100%;
        }
    }

    @media (max-width: 620px) {
        .laundry-workspace .laundry-page-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .laundry-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: 100%;
        }

        .laundry-tab {
            justify-content: center;
            padding-inline: 10px;
        }

        .laundry-transaction-row {
            grid-template-columns: 1fr;
            gap: 12px;
        }
    }
</style>

<div class="laundry-workspace">
    <section class="laundry-page-heading">
        <div>
            <p class="eyebrow">Laundry workspace</p>
            <h1>Laundry Transactions</h1>
            <p>Track current work and review transactions already released or completed.</p>
        </div>
    </section>

    <nav class="laundry-tabs" aria-label="Laundry transaction filters">
        <a
            class="laundry-tab {{ ! $isCompletedTab ? 'active' : '' }}"
            href="{{ route('laundry.index', ['tab' => 'needs-action']) }}"
            @if(! $isCompletedTab) aria-current="page" @endif
        >
            Needs Action
            <span class="laundry-tab-count">{{ $counts['needs_action'] ?? 0 }}</span>
        </a>
        <a
            class="laundry-tab {{ $isCompletedTab ? 'active' : '' }}"
            href="{{ route('laundry.index', ['tab' => 'completed']) }}"
            @if($isCompletedTab) aria-current="page" @endif
        >
            Completed
            <span class="laundry-tab-count">{{ $counts['completed'] ?? 0 }}</span>
        </a>
    </nav>

    <section class="laundry-panel">
        <header class="laundry-panel-header">
            <div>
                <p class="eyebrow">{{ $isCompletedTab ? 'Transaction history' : 'Current work' }}</p>
                <h2>{{ $isCompletedTab ? 'Completed laundry transactions' : 'Transactions requiring attention' }}</h2>
            </div>
            <p class="meta">{{ $jobs->total() }} {{ $jobs->total() === 1 ? 'transaction' : 'transactions' }}</p>
        </header>

        <div class="laundry-transaction-list">
            @forelse($jobs as $job)
                @php
                    $actionCopy = match ($job->status) {
                        'FORM_REPLACEMENT_REQUIRED' => 'Replacement scan required',
                        'FOR_LAUNDRY' => 'Laundry form pending',
                        'RECEIVED_IN_LAUNDRY' => 'Laundry in progress',
                        'READY_FOR_PICKUP' => 'Ready for borrower pickup',
                        'FOR_SPMU_FINAL_CHECK' => 'Released to borrower',
                        'LAUNDRY_COMPLETED' => 'Completed by SPMU',
                        default => str($job->status)->replace('_', ' ')->title(),
                    };
                @endphp

                <article class="laundry-transaction-row">
                    <div class="laundry-primary">
                        <strong>{{ $job->custody->custody_no }}</strong>
                        <small>
                            {{ $job->custody->request->request_no }}
                            &middot; {{ $job->custody->borrower->full_name }}
                        </small>
                    </div>

                    <div class="laundry-cell">
                        <strong>{{ $job->lines->count() }} {{ $job->lines->count() === 1 ? 'item line' : 'item lines' }}</strong>
                        <small>Covered by the Laundry Form</small>
                    </div>

                    <div class="laundry-cell">
                        <x-status-badge :status="$job->status" />
                        <small class="laundry-action-copy">{{ $actionCopy }}</small>
                    </div>

                    <a
                        class="button {{ $isCompletedTab ? 'secondary' : 'primary' }} small ui-pressable laundry-row-action"
                        href="{{ route('laundry.show', $job) }}"
                    >
                        View Details
                    </a>
                </article>
            @empty
                <div class="laundry-empty">
                    <strong>{{ $isCompletedTab ? 'No completed transactions yet.' : 'You are all caught up.' }}</strong>
                    <span>
                        {{
                            $isCompletedTab
                                ? 'Released and completed laundry work will appear here.'
                                : 'New laundry transactions will appear here when action is needed.'
                        }}
                    </span>
                </div>
            @endforelse
        </div>

        @if($jobs->hasPages())
            <div class="laundry-pagination">
                {{ $jobs->links() }}
            </div>
        @endif
    </section>
</div>
@endsection