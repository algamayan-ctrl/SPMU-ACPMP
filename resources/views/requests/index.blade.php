@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Requests' : 'Requests'])
@section('content')
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
@endphp
<section class="page-heading">
    <div><p class="eyebrow">Borrowing requests</p><h1>{{ $isBorrower ? 'My Requests' : 'Borrowing Requests' }}</h1></div>
    @if($isBorrower)<a class="button primary ui-pressable" href="{{ route('requests.create') }}">New Request</a>@endif
</section>
<section class="content-area">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Request</th><th>Purpose</th><th>Schedule</th><th>Return</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($requests as $record)
                @php
                    $v = $record->currentVersion;
                @endphp
                <tr>
                    <td><strong>{{ $record->request_no }}</strong>@unless($isBorrower)<small>{{ $record->borrower?->full_name }}</small>@endunless</td>
                    <td>{{ $v?->purpose_event ?: '—' }}</td>
                    <td>{{ optional($v?->schedule_date ?: $v?->needed_from)->format('d M Y') ?: '—' }}</td>
                    <td>{{ optional($v?->return_date ?: $v?->return_due_at)->format('d M Y') ?: '—' }}</td>
                    <td><x-status-badge :status="$record->status->value" /></td>
                    <td><a class="table-action" href="{{ route('requests.show', $record) }}">View</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-state">No borrowing requests found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
