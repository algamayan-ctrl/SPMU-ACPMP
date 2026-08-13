<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' | ' : '' }}{{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
    @php
        $activeWorkspace = strtoupper((string) session('active_workspace'));
        $allowedWorkspaces = auth()->user()->allowedWorkspaces();
        $unread = App\Models\NotificationDelivery::where('recipient_user_id',auth()->id())->where('channel','SYSTEM')->whereNull('read_at')->count();
        $workspaceLabels = [
            'BORROWER' => 'Borrower',
            'SPMU' => 'SPMU',
            'GSU' => 'GSU Approver',
            'VPAF' => 'VPAF Approver',
            'ICTU' => 'ICTU',
        ];
        $navigation = match ($activeWorkspace) {
            'BORROWER' => [
                ['dashboard', 'dashboard', 'Dashboard', '⌂'],
                ['inventory.index', 'inventory.*', 'Available Items', '▦'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', '□'],
                ['requests.index', 'requests.*', 'My Requests', '≡'],
                ['custody.index', 'custody.*', 'My Borrowings', '↔'],
                ['accountability.index', 'accountability.*', 'Accountability', '!'],
            ],
            'SPMU' => [
                ['dashboard', 'dashboard', 'Dashboard', '⌂'],
                ['approvals.index', 'approvals.*', 'Approval Queue', '✓'],
                ['requests.index', 'requests.*', 'All Requests', '≡'],
                ['inventory.index', 'inventory.*', 'Inventory', '▦'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', '□'],
                ['custody.index', 'custody.*', 'Release and Return', '↔'],
                ['accountability.index', 'accountability.*', 'Accountability', '!'],
                ['reports.index', 'reports.index', 'Reports', '▤'],
                ['administration.index', 'administration.*', 'Configuration', '⚙'],
            ],
            'GSU' => [
                ['dashboard', 'dashboard', 'Dashboard', '⌂'],
                ['approvals.index', 'approvals.*', 'Approval Queue', '✓'],
                ['requests.index', 'requests.*', 'Request Records', '≡'],
                ['inventory.index', 'inventory.*', 'Inventory View', '▦'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', '□'],
            ],
            'VPAF' => [
                ['dashboard', 'dashboard', 'Dashboard', '⌂'],
                ['approvals.index', 'approvals.*', 'Approval Queue', '✓'],
                ['requests.index', 'requests.*', 'Request Records', '≡'],
                ['inventory.index', 'inventory.*', 'Inventory View', '▦'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', '□'],
                ['reports.index', 'reports.*', 'Reports', '▤'],
            ],
            'ICTU' => [
                ['dashboard', 'dashboard', 'Dashboard', '⌂'],
                ['administration.users.index', 'administration.users.*', 'User Accounts', '♙'],
                ['administration.delegations.index', 'administration.delegations.*', 'Delegated Approvers', '✓'],
                ['administration.settings.index', 'administration.settings.*', 'System Settings', '⚙'],
                ['reports.audit', 'reports.audit', 'Audit Trail', '▤'],
                ['reports.notifications', 'reports.notifications', 'Delivery Records', '✉'],
            ],
            default => [],
        };
        $initials = collect(preg_split('/\s+/', trim(auth()->user()->full_name)))->filter()->take(2)->map(fn ($part) => strtoupper(substr($part, 0, 1)))->join('');
    @endphp
    <div class="app-shell">
        <aside class="app-sidebar" id="primary-sidebar">
            <a class="brand sidebar-brand" href="{{ route('dashboard') }}" aria-label="SPMU-ACPMP dashboard">
                <span class="brand-mark">SP</span>
                <span><strong>SPMU-ACPMP</strong><small>CSPC asset borrowing</small></span>
            </a>
            <div class="sidebar-context"><small>You are using</small><strong>{{ $workspaceLabels[$activeWorkspace] ?? 'Select a workspace' }}</strong></div>
            <p class="sidebar-label">Main menu</p>
            <nav class="sidebar-nav" aria-label="Primary navigation">
                @foreach($navigation as [$routeName, $routePattern, $label, $icon])
                    <a class="{{ request()->routeIs($routePattern) ? 'active' : '' }}" href="{{ route($routeName) }}" @if(request()->routeIs($routePattern)) aria-current="page" @endif>
                        <span class="nav-icon" aria-hidden="true">{{ $icon }}</span><span>{{ $label }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="sidebar-foot"><span>Supply and Property Management Unit</span><small>Camarines Sur Polytechnic Colleges</small></div>
        </aside>
        <div class="app-stage">
            <header class="app-topbar">
                <button class="menu-toggle" type="button" aria-label="Open main menu" aria-controls="primary-sidebar" aria-expanded="false" onclick="document.body.classList.toggle('sidebar-open');this.setAttribute('aria-expanded',document.body.classList.contains('sidebar-open'))">☰ <span>Menu</span></button>
                <div class="topbar-title"><strong>{{ isset($title) ? $title : 'Dashboard' }}</strong><small>{{ $workspaceLabels[$activeWorkspace] ?? 'SPMU-ACPMP' }} workspace</small></div>
                <nav class="account-nav" aria-label="Account navigation">
                    @if(count($allowedWorkspaces)>1)<a class="topbar-link" href="{{ route('workspace.choose') }}">Change workspace</a>@endif
                    <a class="topbar-link" href="{{ route('notifications.index') }}">Notifications @if($unread)<span class="badge">{{ $unread }}</span>@endif</a>
                    <a class="topbar-user" href="{{ route('profile.show') }}"><span class="user-avatar">{{ $initials ?: 'U' }}</span><span><strong>{{ auth()->user()->full_name }}</strong><small>View profile</small></span></a>
                    <form action="{{ route('logout') }}" method="post" class="inline-form">@csrf<button class="link-button" type="submit">Sign out</button></form>
                </nav>
            </header>
            <main class="app-main">
                @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
                @if($errors->any())
                    <div class="notice error" role="alert"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                @yield('content')
            </main>
        </div>
    </div>
@else
    <header class="site-header public-header">
        <a class="brand" href="{{ route('home') }}" aria-label="SPMU-ACPMP home"><span class="brand-mark">SA</span><span><strong>SPMU-ACPMP</strong><small>Asset custody and monitoring</small></span></a>
        <nav><a href="{{ route('login') }}">Sign in</a></nav>
    </header>
    <main>
        @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
    <footer><span>Camarines Sur Polytechnic Colleges</span><span>Official operational time: Asia/Manila</span></footer>
@endauth
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
