@extends('layouts.app', ['title' => 'SPMU Verification'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU verification</p>
        <h1>Requests Awaiting Verification</h1>
        <p>Open each request to inspect the scanned approved document before making a decision. Approval is the reservation point.</p>
    </div>
</section>

<section class="content-area">
@forelse($requests as $record)
    @php
        $v = $record->currentVersion;
        $requestLetter = $v->supportingDocuments
            ->where('is_current', true)
            ->firstWhere(
                'document_type',
                App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
            );
    @endphp

    <article class="card top-gap">
        <div class="card-header">
            <div>
                <strong>{{ $record->request_no }}</strong>
                <h2>{{ $record->borrower->full_name }}</h2>
            </div>
            <x-status-badge :status="$record->status->value" />
        </div>

        <dl class="summary-grid compact">
            <div>
                <dt>Purpose</dt>
                <dd>{{ $v->purpose_event }}</dd>
            </div>
            <div>
                <dt>Schedule Date</dt>
                <dd>{{ optional($v->schedule_date ?: $v->needed_from)->format('d M Y') }}</dd>
            </div>
            <div>
                <dt>Return Date</dt>
                <dd>{{ optional($v->return_date ?: $v->return_due_at)->format('d M Y') }}</dd>
            </div>
            <div>
                <dt>Items</dt>
                <dd>{{ $v->items->count() }} item type(s)</dd>
            </div>
            <div>
                <dt>Scanned Request Letter</dt>
                <dd>{{ $requestLetter ? 'Attached' : 'Missing' }}</dd>
            </div>
        </dl>

        <div class="actions">
            <a
                class="button primary ui-pressable"
                href="{{ route('requests.show', $record) }}"
            >
                {{ $canDecide ? 'Review & Verify' : 'Review Request & Documents' }}
            </a>
        </div>

        @unless($canDecide)
            <p class="meta top-gap">
                Decision controls are available to the SPMU Head or to an SPMU Action Officer with an active formal delegation.
            </p>
        @endunless
    </article>
@empty
    <div class="empty-state">
        <strong>No requests awaiting SPMU verification.</strong>
    </div>
@endforelse
</section>
@endsection
