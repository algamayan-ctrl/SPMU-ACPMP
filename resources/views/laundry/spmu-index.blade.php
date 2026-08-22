@extends('layouts.app', ['title' => 'Laundry Final Acceptance'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU Action Officer</p>
        <h1>Laundry Final Acceptance</h1>
        <p>Receive cleaned linen directly from the Laundry Worker, perform the final quantity/condition inspection, and return the signed physical Laundry Form to Laundry for final upload.</p>
    </div>
</section>
<section class="content-area">
    <article class="card">
        <div class="document-list">
            @forelse($jobs as $job)
                @php
                    $statusText = match($job->status) {
                        'READY_FOR_SPMU_RETURN' => 'Laundry Worker is to bring cleaned linen + physical Laundry Form to SPMU.',
                        'AWAITING_FINAL_FORM_UPLOAD' => 'SPMU final physical acceptance is complete; waiting for Laundry Worker final form upload.',
                        'FORM_REPLACEMENT_REQUIRED' => 'Waiting for Laundry Worker to upload a clear final signed form.',
                        default => str($job->status)->replace('_',' ')->title(),
                    };
                @endphp
                <article>
                    <div>
                        <strong>{{ $job->custody->request->request_no }}</strong>
                        <span>{{ $job->custody->borrower->full_name }} · {{ $job->custody->custody_no }}</span>
                        <small>{{ $statusText }}</small>
                    </div>
                    <div class="inline-actions"><x-status-badge :status="$job->status" /><a class="button primary small ui-pressable" href="{{ route('custody.show', $job->custody) }}">Review</a></div>
                </article>
            @empty
                <div class="empty-state"><strong>No Laundry final-acceptance cases need action.</strong></div>
            @endforelse
        </div>
        @if($jobs->hasPages())<div class="top-gap">{{ $jobs->links() }}</div>@endif
    </article>
</section>
@endsection
