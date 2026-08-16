@extends('layouts.app', ['title' => 'User Administration'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">ICTU identity administration</p>
        <h1>Institutional user accounts</h1>
        <p>Register CSPC identities, classify access, and retain an attributable assignment history.</p>
    </div>
    @if(Route::has('administration.users.create'))
        <a class="button primary ui-pressable" href="{{ route('administration.users.create') }}">Register account</a>
    @endif
</section>

<section class="content-area">
    <div class="table-wrap admin-table-wrap">
        <table class="admin-user-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Borrower / Employee No.</th>
                    <th>Email</th>
                    <th>Organizational Unit</th>
                    <th>Classification</th>
                    <th>Account Status</th>
                    <th>Restriction Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>
                            <strong>{{ $user->full_name }}</strong>
                            <small>{{ $user->designation ?: 'No designation provided' }}</small>
                        </td>
                        <td>{{ $user->employee_no ?: 'Not set' }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->organizationalUnit?->unit_name ?: 'No assignment' }}</td>
                        <td>{{ $user->access_classification?->label() ?? 'Not assigned' }}</td>
                        <td><x-status-badge :status="$user->account_status ?? 'ACTIVE'" /></td>
                        <td>
                            @if($user->activeRestrictions()->exists())
                                <x-status-badge status="BORROWING_RESTRICTED" />
                            @else
                                <span class="status-badge status-neutral">Not restricted</span>
                            @endif
                        </td>
                        <td class="actions-cell">
                            <div class="action-group">
                                <a class="table-action" href="{{ route('administration.users.edit', $user) }}">Manage account</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">No accounts match the selected filters.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
