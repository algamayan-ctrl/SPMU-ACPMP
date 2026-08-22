@extends('layouts.app', ['title' => 'For Approval'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU Head / Admin</p>
        <h1>Requests for Approval</h1>
        <p>Review the submitted request, signed documents, quantities, dates, and current availability. Verify & Approve allocates/holds the approved quantities for pickup; physical issuance happens later through the SPMU Action Officer.</p>
    </div>
    <a class="button secondary ui-pressable" href="{{ route('requests.index') }}">View Request Records</a>
</section>

<section class="content-area">
    <div class="operational-record-list">
        @forelse($requests as $record)
            @php
                $v = $record->currentVersion;
                $requestLetter = $v->supportingDocuments
                    ->where('is_current', true)
                    ->firstWhere('document_type', App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER);
            @endphp
            <a class="operational-record ui-pressable" href="{{ route('requests.show', $record) }}">
                <span class="operational-record-primary">
                    <strong>{{ $record->request_no }}</strong>
                    <span>{{ $record->borrower->full_name }}</span>
                    <small>{{ $v->purpose_event }} · {{ optional($v->schedule_date ?: $v->needed_from)->format('d M Y') }} → {{ optional($v->return_date ?: $v->return_due_at)->format('d M Y') }}</small>
                </span>
                <span class="operational-record-facts">
                    <span><small>Signed BR Letter</small><strong>{{ $requestLetter ? 'Attached' : 'Missing' }}</strong></span>
                    <span><small>Items</small><strong>{{ $v->items->count() }}</strong></span>
                    <span><small>Stage</small><strong>Head Review</strong></span>
                </span>
                <span class="operational-record-action">
                    <x-status-badge :status="$record->status->value" label="For Approval" />
                    <strong>Review <x-icon name="chevron-right" size="16" /></strong>
                </span>
            </a>
        @empty
            <div class="empty-state">
                <strong>No borrowing requests are waiting for Head approval.</strong>
                <span>New submitted requests will appear here automatically.</span>
            </div>
        @endforelse
    </div>
</section>
@endsection
