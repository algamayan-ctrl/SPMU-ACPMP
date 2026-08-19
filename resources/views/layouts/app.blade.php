<!doctype html>
<html lang="en" @auth data-theme-storage-key="spmu-acpmp.appearance.{{ hash('sha256', (string) auth()->id()) }}" @endauth>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' | ' : '' }}{{ config('app.name') }}</title>
    <script>
        (() => {
            const root = document.documentElement;
            const storageKey = root.dataset.themeStorageKey;
            let preference = 'light';

            if (storageKey) {
                try {
                    const stored = localStorage.getItem(storageKey);
                    if (['light', 'dark', 'system'].includes(stored)) preference = stored;
                } catch (_) {}
            }

            const resolved = preference === 'dark' ? 'dark' : 'light';

            root.dataset.theme = resolved;
            root.dataset.themePreference = preference;
            root.style.colorScheme = resolved;
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
@auth
    @php
        $activeWorkspace = auth()->user()->primaryWorkspace();
        $unread = App\Models\NotificationDelivery::where('recipient_user_id', auth()->id())->where('channel', 'SYSTEM')->whereNull('read_at')->count();
        $navigation = match ($activeWorkspace) {
            'BORROWER' => [
                ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
                ['inventory.index', 'inventory.*', 'Inventory', 'inventory'],
                ['requests.index', 'requests.*', 'My Requests', 'requests'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', 'calendar'],
                ['custody.index', 'custody.*', 'My Borrowings', 'custody'],
                ['accountability.index', 'accountability.*', 'Accountability', 'accountability'],
            ],
            'SPMU' => [
                ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
                ['approvals.index', 'approvals.*', 'Approval Queue', 'approval'],
                ['requests.index', 'requests.*', 'All Requests', 'requests'],
                ['inventory.index', 'inventory.*', 'Inventory', 'inventory'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', 'calendar'],
                ['custody.index', 'custody.*', 'Release and Return', 'custody'],
                ['accountability.index', 'accountability.*', 'Accountability', 'accountability'],
                ['reports.index', 'reports.index', 'Reports', 'reports'],
                ['administration.index', 'administration.*', 'Configuration', 'settings'],
            ],
            'GSU' => [
                ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
                ['approvals.index', 'approvals.*', 'Approval Queue', 'approval'],
                ['requests.index', 'requests.*', 'Request Records', 'requests'],
                ['inventory.index', 'inventory.*', 'Inventory View', 'inventory'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', 'calendar'],
            ],
            'VPAF' => [
                ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
                ['approvals.index', 'approvals.*', 'Approval Queue', 'approval'],
                ['requests.index', 'requests.*', 'Request Records', 'requests'],
                ['inventory.index', 'inventory.*', 'Inventory View', 'inventory'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', 'calendar'],
                ['reports.index', 'reports.*', 'Reports', 'reports'],
            ],
            'ICTU' => [
                ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
                ['administration.users.index', 'administration.users.*', 'User Accounts', 'users'],
                ['administration.delegations.index', 'administration.delegations.*', 'Delegated Approvers', 'delegation'],
                ['administration.settings.index', 'administration.settings.*', 'System Settings', 'settings'],
                ['reports.audit', 'reports.audit', 'Audit Trail', 'reports'],
                ['reports.notifications', 'reports.notifications', 'Delivery Records', 'notifications'],
            ],
            default => [],
        };
    @endphp
    <div class="app-shell">
        <aside class="app-sidebar" id="primary-sidebar">
            <div class="sidebar-brand-row">
                <a class="brand sidebar-brand" href="{{ route('dashboard') }}" aria-label="SPMU-ACPMP dashboard">
                    <span class="brand-mark">SP</span>
                    <span><strong>SPMU-ACPMP</strong><small>CSPC asset borrowing</small></span>
                </a>
                <button class="icon-button sidebar-close" type="button" aria-label="Close main menu" title="Close main menu" data-sidebar-close><x-icon name="close" /></button>
            </div>
            <p class="sidebar-label">Main menu</p>
            <nav class="sidebar-nav" aria-label="Primary navigation">
                @foreach($navigation as [$routeName, $routePattern, $label, $icon])
                    <a class="interactive {{ request()->routeIs($routePattern) ? 'active' : '' }}" href="{{ route($routeName) }}" @if(request()->routeIs($routePattern)) aria-current="page" @endif>
                        <span class="nav-icon"><x-icon :name="$icon" /></span><span>{{ $label }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="sidebar-foot"><span>Supply and Property Management Unit</span><small>Camarines Sur Polytechnic Colleges</small></div>
        </aside>
        <button class="sidebar-backdrop" type="button" aria-label="Close main menu" tabindex="-1" data-sidebar-close></button>
        <div class="app-stage">
            <header class="app-topbar">
                <button class="icon-button menu-toggle" type="button" aria-label="Open main menu" title="Open main menu" aria-controls="primary-sidebar" aria-expanded="false" data-sidebar-toggle><x-icon name="menu" /></button>
                <div class="topbar-title"><strong>{{ isset($title) ? $title : 'Dashboard' }}</strong></div>
                <nav class="account-nav" aria-label="Account navigation">
                    <a class="icon-button interactive notification-control" href="{{ route('notifications.index') }}" aria-label="Notifications{{ $unread ? ': '.$unread.' unread' : '' }}" title="Notifications">
                        <x-icon name="notifications" />
                        @if($unread)<span class="notification-count" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
                    </a>
                    <x-account-menu :user="auth()->user()" />
                </nav>
            </header>
            <main class="app-main" id="main-content" tabindex="-1">
                @if(session('status'))<div class="notice success" role="status"><x-icon name="success" /><div>{{ session('status') }}</div></div>@endif
                @if($errors->any())
                    <div class="notice error" role="alert"><x-icon name="error" /><div><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
                @endif
                @yield('content')
            </main>
        </div>
    </div>
@else
    <header class="site-header public-header">
        <a class="brand" href="{{ route('home') }}" aria-label="SPMU-ACPMP home"><span class="brand-mark">SA</span><span><strong>SPMU-ACPMP</strong><small>Asset custody and monitoring</small></span></a>
        <nav><a href="#how-it-works">Learn more</a><a href="#help">Help</a><a href="{{ route('login') }}">Sign in</a></nav>
    </header>
    <main id="main-content" tabindex="-1">
        @if(session('status'))<div class="notice success" role="status"><x-icon name="success" /><div>{{ session('status') }}</div></div>@endif
        @if($errors->any())<div class="notice error" role="alert"><x-icon name="error" /><div><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
        @yield('content')
    </main>
    <footer><span>Camarines Sur Polytechnic Colleges</span><span>Official operational time: Asia/Manila</span></footer>
@endauth
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
