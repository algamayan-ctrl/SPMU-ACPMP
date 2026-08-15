@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Requests' : 'Borrowing Requests'])
@section('content')
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
@endphp
<section class="page-heading">
    <div>
        <p class="eyebrow">Request tracking</p>
        <h1>{{ $isBorrower ? 'My Requests' : 'Borrowing request records' }}</h1>
    </div>
    @if($isBorrower)<a class="button primary ui-pressable" href="{{ route('requests.create') }}"><x-icon name="plus" size="17" />Create new request</a>@endif
</section>

<section class="content-area">
@if($isBorrower)
    <div class="request-list" aria-label="My borrowing requests">
        @forelse($requests as $request)
            @php
                $version = $request->currentVersion;
                $action = match($request->status) {
                    App\Enums\RequestStatus::Draft => ['Continue editing', 'Complete the draft, review the request letter, then submit it.'],
                    App\Enums\RequestStatus::ReturnedForRevision => ['Revise request', 'Review the recorded remarks and submit a corrected version.'],
                    App\Enums\RequestStatus::FinalApprovedAwaitingDownload => ['Download approved letter', 'Open the request and download the approved letter before the deadline.'],
                    App\Enums\RequestStatus::ApprovedReadyForRelease => ['View release status', 'Your approved request is ready for SPMU release processing.'],
                    App\Enums\RequestStatus::UnderSpmu => ['View progress', 'Waiting for SPMU review. No action is required right now.'],
                    App\Enums\RequestStatus::UnderGsu => ['View progress', 'Waiting for GSU review. No action is required right now.'],
                    App\Enums\RequestStatus::UnderVpaf => ['View progress', 'Waiting for VPAF review. No action is required right now.'],
                    App\Enums\RequestStatus::Rejected => ['View decision', 'Review the final decision and remarks.'],
                    App\Enums\RequestStatus::Cancelled => ['View record', 'This request was cancelled.'],
                    App\Enums\RequestStatus::Expired => ['View record', 'The approved-letter download period expired.'],
                    default => ['View progress', 'Open the request for its latest status.'],
                };
                $requiresAction = in_array($request->status, [
                    App\Enums\RequestStatus::Draft,
                    App\Enums\RequestStatus::ReturnedForRevision,
                    App\Enums\RequestStatus::FinalApprovedAwaitingDownload,
                ], true);
            @endphp
            <a class="request-list-item ui-pressable {{ $requiresAction ? 'is-action-required' : '' }}" href="{{ route('requests.show', $request) }}">
                <span class="request-list-main">
                    <span class="request-list-purpose">{{ $version?->purpose_event ?: 'Borrowing request' }}</span>
                    <span class="request-list-heading"><span class="record-reference">{{ $request->request_no }}</span><x-status-badge :status="$request->status" /></span>
                    <small>{{ $action[1] }}</small>
                </span>
                <span class="request-list-meta">
                    <span>{{ optional($version?->needed_from)->format('d M Y, g:i A') ?: 'Schedule pending' }}</span>
                    <small>to {{ optional($version?->return_due_at)->format('d M Y, g:i A') ?: 'Not set' }}</small>
                    <small>{{ $version?->items->count() ?? 0 }} item type(s) · Updated {{ $request->updated_at->format('d M Y') }}</small>
                </span>
                <span class="request-list-action {{ $requiresAction ? 'is-required' : '' }}">{{ $action[0] }}<x-icon name="chevron-right" /></span>
            </a>
        @empty
            <div class="empty-state borrower-empty-state request-empty-state">
                <div><strong>No borrowing requests yet.</strong><span>Create your first borrowing request when you need institutional property.</span></div>
                <a class="button primary ui-pressable" href="{{ route('requests.create') }}"><x-icon name="plus" size="17" />Create new request</a>
            </div>
        @endforelse
    </div>
@else
    <div class="table-wrap"><table><thead><tr><th>Request</th><th>Borrower</th><th>Event and period</th><th>Items</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($requests as $request)<tr><td><strong>{{ $request->request_no }}</strong><small>Version {{ $request->current_version_no }}</small></td><td>{{ $request->borrower->full_name }}</td><td>{{ $request->currentVersion?->purpose_event }}<small>{{ optional($request->currentVersion?->needed_from)->format('d M Y, g:i A') }} to {{ optional($request->currentVersion?->return_due_at)->format('d M Y, g:i A') }}</small></td><td>{{ $request->currentVersion?->items->count() ?? 0 }} item type(s)</td><td><x-status-badge :status="$request->status" /></td><td><a class="table-action" href="{{ route('requests.show', $request) }}">View details</a></td></tr>@empty<tr><td colspan="6" class="empty-state">No borrowing requests found.</td></tr>@endforelse
    </tbody></table></div>
@endif
</section>
@endsection
