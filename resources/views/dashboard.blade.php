@extends('layouts.app', ['title' => $workspace.' Dashboard'])

@section('content')
@php
    $workspaceName = match($workspace) {
        'BORROWER' => 'Borrower',
        'SPMU' => 'SPMU',
        'GSU' => 'GSU Approval',
        'VPAF' => 'VPAF Approval',
        default => 'ICTU Administration',
    };
    $firstName = explode(' ', trim($user->full_name))[0];
    $queueLink = match($workspace) {
        'BORROWER' => route('requests.index'),
        'SPMU', 'GSU', 'VPAF' => route('approvals.index'),
        default => route('administration.users.index'),
    };
@endphp
<section class="page-heading dashboard-heading">
    <div>
        <p class="eyebrow">{{ $workspaceName }} dashboard</p>
        <h1>Welcome, {{ $firstName }}</h1>
        <p>{{ match($workspace) {
            'BORROWER' => 'Start a request, check its progress, and see what you need to return.',
            'SPMU' => 'See the requests, releases, returns, and inventory tasks that need attention.',
            'GSU' => 'Review the requests waiting for GSU approval.',
            'VPAF' => 'Review final approvals and monitor allocated property.',
            default => 'Manage user access, temporary approvers, settings, and system records.',
        } }}</p>
    </div>
    <div class="workspace-chip"><span>Current workspace</span><strong>{{ $workspaceName }}</strong></div>
</section>

<section class="stat-grid" aria-label="Workspace totals">
    @foreach($statistics as $label => $value)
        <article><span class="stat-symbol" aria-hidden="true">{{ $loop->iteration }}</span><div><span>{{ $label }}</span><small>Updated from current records</small></div><strong>{{ number_format($value) }}</strong></article>
    @endforeach
</section>

<section class="content-grid dashboard-grid">
    <article class="card queue-card">
        <div class="card-header"><div><p class="eyebrow">Your task list</p><h2>{{ $workspace === 'ICTU' ? 'Recently added accounts' : 'What needs your attention' }}</h2></div>
            <a href="{{ $queueLink }}">View all</a>
        </div>
        <div class="dashboard-list">
            @forelse($queue as $record)
                @if($workspace === 'ICTU')
                    <article><div><strong>{{ $record->full_name }}</strong><small>{{ $record->email }} · {{ $record->organizationalUnit?->unit_name }}</small></div><span class="status">{{ str_replace('_',' ',$record->access_classification?->value ?? 'Unclassified') }}</span></article>
                @else
                    <article><div><a href="{{ route('requests.show',$record) }}"><strong>{{ $record->request_no }}</strong></a><small>{{ $workspace === 'BORROWER' ? $record->currentVersion?->purpose_event : $record->borrower?->full_name.' · '.$record->currentVersion?->purpose_event }}</small></div><span class="status status-{{ strtolower($record->status->value) }}">{{ $record->status->label() }}</span></article>
                @endif
            @empty
                <div class="empty-state"><strong>You are up to date</strong><span>Nothing requires your attention right now.</span></div>
            @endforelse
        </div>
    </article>

    <aside class="card quick-card">
        <p class="eyebrow">Shortcuts</p><h2>Common tasks</h2>
        <nav class="quick-actions" aria-label="Workspace quick actions">
            @if($workspace === 'BORROWER')
                <a href="{{ route('requests.create') }}"><strong>Create a borrowing request</strong><span>Choose available items and your borrowing dates.</span><b aria-hidden="true">›</b></a>
                <a href="{{ route('custody.index') }}"><strong>Open my Borrower Slips</strong><span>View documents, returns, and required actions.</span><b aria-hidden="true">›</b></a>
            @elseif($workspace === 'SPMU')
                <a href="{{ route('approvals.index') }}"><strong>Review requests</strong><span>Open the SPMU approval queue.</span><b aria-hidden="true">›</b></a>
                <a href="{{ route('custody.index') }}"><strong>Process a release or return</strong><span>Record quantities, signatures, and inspection.</span><b aria-hidden="true">›</b></a>
                <a href="{{ route('inventory.index') }}"><strong>Update inventory</strong><span>Maintain descriptions, quantities, and availability.</span><b aria-hidden="true">›</b></a>
            @elseif(in_array($workspace,['GSU','VPAF'],true))
                <a href="{{ route('approvals.index') }}"><strong>Open approval queue</strong><span>Review requests waiting for your decision.</span><b aria-hidden="true">›</b></a>
                <a href="{{ route('calendar.index') }}"><strong>View borrowing calendar</strong><span>See confirmed schedules and deadlines.</span><b aria-hidden="true">›</b></a>
            @else
                <a href="{{ route('administration.users.index') }}"><strong>Manage user accounts</strong><span>Register accounts and assign access.</span><b aria-hidden="true">›</b></a>
                <a href="{{ route('administration.delegations.index') }}"><strong>Manage delegated approvers</strong><span>Record temporary approval authority.</span><b aria-hidden="true">›</b></a>
                <a href="{{ route('reports.audit') }}"><strong>Review the audit trail</strong><span>See technical and user actions.</span><b aria-hidden="true">›</b></a>
            @endif
        </nav>
    </aside>
</section>

@if($workspace !== 'ICTU')
<section class="content-area dashboard-deadlines">
    <article class="card">
        <div class="card-header"><div><p class="eyebrow">Calendar preview</p><h2>{{ $workspace === 'BORROWER' ? 'My upcoming deadlines' : 'Upcoming custody deadlines' }}</h2></div><a href="{{ route('calendar.index') }}">Open calendar</a></div>
        <div class="deadline-strip">
            @forelse($nextCustodies as $custody)
                <a href="{{ route('custody.show',$custody) }}"><time>{{ $custody->due_at->format('M d') }}<small>{{ $custody->due_at->format('Y') }}</small></time><span><strong>{{ $custody->custody_no }}</strong><small>{{ $workspace === 'BORROWER' ? $custody->request->request_no : $custody->borrower->full_name }}</small></span><span class="status">{{ str_replace('_',' ',$custody->status) }}</span></a>
            @empty
                <div class="empty-state"><strong>No upcoming returns</strong><span>Active return deadlines will appear here.</span></div>
            @endforelse
        </div>
    </article>
</section>
@endif
@endsection
