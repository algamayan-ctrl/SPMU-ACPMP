@extends('layouts.app', ['title' => 'Laundry Requests'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">Simple Laundry Mode</p>
        <h1>Laundry Requests</h1>
        <p>
            No detailed computer encoding is required here.
            Use the physical Laundry Form while working, then upload the accomplished scan.
        </p>
    </div>
</section>

<section class="content-area narrow">
    <div class="callout info">
        <strong>Only two system actions are needed.</strong>
        <p>
            1) Upload the accomplished Laundry Form when washing is complete.
            2) Mark the cleaned linen Released to Borrower when the borrower collects it.
        </p>
    </div>
</section>

<section class="content-area">
    <div class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Current work</p>
                <h2>Open laundry requests</h2>
            </div>
        </div>

        <div class="document-list">
            @forelse($jobs as $job)
                <article>
                    <div>
                        <strong>{{ $job->custody->custody_no }}</strong>
                        <small>
                            {{ $job->custody->request->request_no }}
                            ·
                            Borrower: {{ $job->custody->borrower->full_name }}
                        </small>
                        <small>
                            {{ $job->lines->count() }} linen item line(s)
                        </small>
                    </div>

                    <div class="inline-actions">
                        <x-status-badge :status="$job->status" />
                        <a
                            class="button primary small ui-pressable"
                            href="{{ route('laundry.show', $job) }}"
                        >
                            Open
                        </a>
                    </div>
                </article>
            @empty
                <div class="empty-state">
                    <strong>No open laundry request.</strong>
                    <span>New linen cases will appear here after SPMU physically issues the approved linen.</span>
                </div>
            @endforelse
        </div>

        @if($jobs->hasPages())
            <div class="top-gap">
                {{ $jobs->links() }}
            </div>
        @endif
    </div>
</section>
@endsection
