@extends('layouts.app', ['title' => 'Laundry Requests'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">Laundry Worker</p>
        <h1>Laundry Requests</h1>
        <p>Process linen from borrower turnover through direct return to SPMU and final signed-form upload.</p>
    </div>
    <a class="button secondary ui-pressable" href="{{ route('laundry.completed') }}">Completed</a>
</section>

<section class="content-area">
    <article class="card">
        <div class="callout info">
            <strong>Final linen custody chain</strong>
            <p>1) Borrower signs and hands used linen + physical Laundry Form to Laundry. 2) Laundry records receipt, processes, and signs. 3) Laundry Worker brings cleaned linen + same form directly to SPMU. 4) SPMU signs final acceptance. 5) Laundry uploads the fully signed form to complete/settle the Laundry transaction.</p>
        </div>

        <div class="document-list top-gap">
            @forelse($jobs as $job)
                @php
                    $statusText = match($job->status) {
                        'FOR_LAUNDRY' => 'Waiting for borrower turnover',
                        'IN_PROCESS' => 'Laundry processing in progress',
                        'READY_FOR_SPMU_RETURN' => 'Cleaned linen ready to bring directly to SPMU',
                        'AWAITING_FINAL_FORM_UPLOAD' => 'SPMU accepted linen; upload fully signed form',
                        'FORM_REPLACEMENT_REQUIRED' => 'Upload a clear replacement final signed form',
                        default => str($job->status)->replace('_', ' ')->title(),
                    };
                @endphp
                <article>
                    <div>
                        <strong>{{ $job->custody->request->request_no }}</strong>
                        <span>{{ $job->custody->borrower->full_name }} · {{ $job->custody->custody_no }}</span>
                        <small>{{ $statusText }}</small>
                    </div>
                    <div class="inline-actions">
                        <x-status-badge :status="$job->status" />
                        <a class="button primary small ui-pressable" href="{{ route('laundry.show', $job) }}">Open</a>
                    </div>
                </article>
            @empty
                <div class="empty-state"><strong>No Laundry cases need action.</strong><p>New linen cases appear here after SPMU physically releases laundry-required items.</p></div>
            @endforelse
        </div>

        @if($jobs->hasPages())<div class="top-gap">{{ $jobs->links() }}</div>@endif
    </article>
</section>
@endsection
