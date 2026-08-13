@extends('layouts.app', ['title' => 'Choose Workspace'])
@section('content')
<section class="workspace-shell">
    <div class="workspace-intro"><p class="eyebrow">Choose what you need to do</p><h1>Select a workspace</h1><p>Your available workspaces are based on your registered responsibilities. You can change workspace later.</p></div>
    <div class="workspace-grid">
    @foreach($workspaces as $workspace)
        <form method="post" action="{{ route('workspace.select') }}" class="workspace-card">@csrf
            <input type="hidden" name="workspace" value="{{ $workspace }}">
            <span class="workspace-icon">{{ substr($workspace,0,1) }}</span>
            <div><span class="eyebrow">{{ $workspace }}</span><h2>{{ match($workspace) {'BORROWER'=>'Borrower','SPMU'=>'SPMU Operations','ICTU'=>'ICTU Administration','GSU'=>'GSU Approval','VPAF'=>'VPAF Approval'} }}</h2>
            <p>{{ match($workspace) {'BORROWER'=>'Create requests, plan against availability, and manage your own custody.','SPMU'=>'Verify requests, control inventory, release and returns, and resolve accountability.','ICTU'=>'Manage institutional accounts, access, delegation, system health, and backups.','GSU'=>'Review assigned requests as the authorized GSU approver.','VPAF'=>'Complete final approval and management oversight.'} }}</p></div>
            <button class="button primary">Continue as {{ match($workspace) {'BORROWER'=>'Borrower','SPMU'=>'SPMU','ICTU'=>'ICTU','GSU'=>'GSU Approver','VPAF'=>'VPAF Approver'} }}</button>
        </form>
    @endforeach
    </div>
</section>
@endsection
