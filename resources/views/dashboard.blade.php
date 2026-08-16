@extends('layouts.app', ['title' => 'Dashboard'])

@section('content')

@php
    $firstName = explode(
        ' ',
        trim($user->full_name)
    )[0];


    /*
    |--------------------------------------------------------------------------
    | Main queue links
    |--------------------------------------------------------------------------
    */

    $queueLink = match($workspace) {
        'BORROWER' => route('requests.index'),

        'SPMU',
        'GSU',
        'VPAF' => route('approvals.index'),

        default => route('administration.users.index'),
    };


    /*
    |--------------------------------------------------------------------------
    | Statistic-card links
    |--------------------------------------------------------------------------
    */

    $statLinks = match($workspace) {

        'BORROWER' => [
            'Open requests' => route('requests.index'),
            'Active borrowings' => route('custody.index'),
            'Borrowable items' => route('inventory.index'),
        ],

        'SPMU' => [
            'SPMU review queue' => route('approvals.index'),
            'Release/return cases' => route('custody.index'),
            'Active inventory' => route('inventory.index'),
        ],

        'GSU' => [
            'GSU review queue' => route('approvals.index'),
            'Active inventory' => route('inventory.index'),
        ],

        'VPAF' => [
            'VPAF review queue' => route('approvals.index'),
        ],

        'ICTU' => [
            'Active accounts' => route('administration.users.index'),
            'Failed notifications' => route('reports.notifications'),
            'Active delegations' => route('administration.delegations.index'),
        ],

        default => [],
    };


    /*
    |--------------------------------------------------------------------------
    | Statistic presentation
    |--------------------------------------------------------------------------
    */

    $statPresentation = [
        'Open requests' => [
            'requests',
            'info',
        ],

        'Active borrowings' => [
            'custody',
            'warning',
        ],

        'Borrowable items' => [
            'inventory',
            'success',
        ],

        'SPMU review queue' => [
            'approval',
            'info',
        ],

        'Release/return cases' => [
            'custody',
            'warning',
        ],

        'Active inventory' => [
            'inventory',
            'success',
        ],

        'GSU review queue' => [
            'approval',
            'info',
        ],

        'Forwarded approvals' => [
            'approval',
            'success',
        ],

        'VPAF review queue' => [
            'approval',
            'info',
        ],

        'Final approvals' => [
            'approval',
            'success',
        ],

        'Active custody' => [
            'custody',
            'warning',
        ],

        'Active accounts' => [
            'users',
            'success',
        ],

        'Failed notifications' => [
            'notifications',
            'danger',
        ],

        'Active delegations' => [
            'delegation',
            'warning',
        ],
    ];
@endphp


{{-- ========================================================= --}}
{{-- PAGE HEADING                                              --}}
{{-- ========================================================= --}}

<section class="page-heading dashboard-heading">

    <div>

        <p class="eyebrow">
            Overview
        </p>


        @if($workspace === 'BORROWER')

            <h1>
                Dashboard
            </h1>

        @else

            <h1>
                Welcome, {{ $firstName }}
            </h1>

            <p>
                {{
                    match($workspace) {
                        'SPMU' =>
                            'See the requests, releases, returns, and inventory tasks that need attention.',

                        'GSU' =>
                            'Review the requests waiting for GSU approval.',

                        'VPAF' =>
                            'Review final approvals and monitor allocated property.',

                        default =>
                            'Manage user access, temporary approvers, settings, and system records.',
                    }
                }}
            </p>

        @endif

    </div>

</section>


{{-- ========================================================= --}}
{{-- STATISTIC CARDS                                           --}}
{{-- ========================================================= --}}

<section
    class="stat-grid"
    aria-label="Current totals"
>

    @foreach($statistics as $label => $value)

        @php
            /*
             * Keep the internal controller key as "Borrowable items"
             * while presenting the clearer UI label "Available items".
             */
            $displayLabel =
                $label === 'Borrowable items'
                    ? 'Available items'
                    : $label;

            [
                $statIcon,
                $statTone
            ] = $statPresentation[$label]
                ?? [
                    'dashboard',
                    'neutral'
                ];
        @endphp


        @if(isset($statLinks[$label]))

            <a
                class="
                    card
                    stat-card
                    stat-card-link
                    kpi-card
                    dashboard-kpi-card
                    kpi-accent-{{ $statTone }}
                    ui-pressable
                "
                href="{{ $statLinks[$label] }}"
            >

                <span
                    class="kpi-icon"
                    aria-hidden="true"
                >
                    <x-icon
                        :name="$statIcon"
                        size="18"
                    />
                </span>

                <strong class="kpi-value">
                    {{ number_format($value) }}
                </strong>

                <span class="kpi-label">
                    {{ $displayLabel }}
                </span>

                <span
                    class="stat-card-arrow"
                    aria-hidden="true"
                >
                    <x-icon name="chevron-right" />
                </span>

            </a>

        @else

            <article
                class="
                    card
                    stat-card
                    kpi-card
                    dashboard-kpi-card
                    kpi-accent-{{ $statTone }}
                "
            >

                <span
                    class="kpi-icon"
                    aria-hidden="true"
                >
                    <x-icon
                        :name="$statIcon"
                        size="18"
                    />
                </span>

                <strong class="kpi-value">
                    {{ number_format($value) }}
                </strong>

                <span class="kpi-label">
                    {{ $displayLabel }}
                </span>

            </article>

        @endif

    @endforeach

</section>


{{-- ========================================================= --}}
{{-- DASHBOARD MAIN GRID                                       --}}
{{-- ========================================================= --}}

<section class="dashboard-grid">


    {{-- ===================================================== --}}
    {{-- TASK LIST                                             --}}
    {{-- ===================================================== --}}

    <article
        class="
            card
            queue-card
            {{ $workspace === 'BORROWER' ? 'attention-card' : '' }}
        "
    >

        <div class="card-header">

            <div>

                <p class="eyebrow">
                    Your task list
                </p>

                <h2>
                    {{
                        $workspace === 'ICTU'
                            ? 'Recently added accounts'
                            : 'What needs your attention'
                    }}
                </h2>

            </div>

            <a
                class="dashboard-view-all"
                href="{{ $queueLink }}"
            >
                View all

                <x-icon
                    name="chevron-right"
                    size="16"
                />
            </a>

        </div>


        <div class="queue-list">

            @forelse($queue as $record)


                {{-- ========================================= --}}
                {{-- BORROWER TASK                             --}}
                {{-- ========================================= --}}

                @if($workspace === 'BORROWER')

                    @php
                        /*
                        |--------------------------------------------------------------------------
                        | Operational custody status
                        |--------------------------------------------------------------------------
                        |
                        | The request may remain
                        | APPROVED_READY_FOR_RELEASE in request history even after
                        | physical release.
                        |
                        | Once custody becomes ACTIVE or another post-release state,
                        | custody becomes the operational display status.
                        |
                        */

                        $custody = $record->custody;

                        $custodyStatus =
                            $custody?->status;


                        /*
                         * These custody states indicate that physical release
                         * already happened.
                         */
                        $postReleaseStates = [
                            'ACTIVE',
                            'PARTIALLY_RETURNED',
                            'OVERDUE',
                            'EARLY_RETURN',
                            'INCIDENT_OPEN',
                            'OBLIGATION_OPEN',
                            'CLOSED',
                        ];


                        $hasPostReleaseCustody =
                            $custody
                            && in_array(
                                $custodyStatus,
                                $postReleaseStates,
                                true
                            );


                        /*
                        |--------------------------------------------------------------------------
                        | Effective display status
                        |--------------------------------------------------------------------------
                        */

                        $effectiveStatus = match($custodyStatus) {

                            'ACTIVE' =>
                                'ACTIVE',

                            'PARTIALLY_RETURNED' =>
                                'PARTIALLY_RETURNED',

                            'OVERDUE' =>
                                'OVERDUE',

                            'EARLY_RETURN' =>
                                'EARLY_RETURN',

                            'INCIDENT_OPEN' =>
                                'INCIDENT_OPEN',

                            'OBLIGATION_OPEN' =>
                                'OBLIGATION_OPEN',

                            'CLOSED' =>
                                'CLOSED',

                            default =>
                                $record->status,
                        };


                        /*
                        |--------------------------------------------------------------------------
                        | Human-readable operational label
                        |--------------------------------------------------------------------------
                        */

                        $effectiveStatusLabel = match($custodyStatus) {

                            'ACTIVE' =>
                                'Released',

                            'PARTIALLY_RETURNED' =>
                                'Partially Returned',

                            'OVERDUE' =>
                                'Overdue',

                            'EARLY_RETURN' =>
                                'Early Return',

                            'INCIDENT_OPEN' =>
                                'Incident Open',

                            'OBLIGATION_OPEN' =>
                                'Obligation Open',

                            'CLOSED' =>
                                'Returned',

                            default =>
                                null,
                        };


                        /*
                        |--------------------------------------------------------------------------
                        | Task explanation + action
                        |--------------------------------------------------------------------------
                        */

                        [
                            $taskMessage,
                            $taskAction
                        ] = match($custodyStatus) {

                            /*
                             * Physical release completed.
                             */
                            'ACTIVE' => [
                                'Your approved items have been released and are currently under your custody.',
                                'Open borrowing',
                            ],


                            /*
                             * Some released quantities already returned.
                             */
                            'PARTIALLY_RETURNED' => [
                                'Some items have already been returned. Review the quantities still under your custody.',
                                'Review remaining items',
                            ],


                            /*
                             * Return deadline passed.
                             */
                            'OVERDUE' => [
                                'The return deadline has passed. Review your borrowing and return requirements.',
                                'Review overdue borrowing',
                            ],


                            /*
                             * Early Return process.
                             */
                            'EARLY_RETURN' => [
                                'An Early Return process is currently in progress for this borrowing.',
                                'View return status',
                            ],


                            /*
                             * Accountability incident.
                             */
                            'INCIDENT_OPEN' => [
                                'An accountability incident remains open for this borrowing.',
                                'Review incident',
                            ],


                            /*
                             * Physical items may already be returned but
                             * obligation remains open.
                             */
                            'OBLIGATION_OPEN' => [
                                'The items were returned, but an outstanding obligation still requires resolution.',
                                'Review obligation',
                            ],


                            /*
                             * Borrowing fully completed.
                             */
                            'CLOSED' => [
                                'This borrowing has been completed and the custody record is closed.',
                                'View completed borrowing',
                            ],


                            /*
                             * No post-release custody state yet.
                             *
                             * Fall back to the normal request workflow.
                             */
                            default => match($record->status) {

                                App\Enums\RequestStatus::Draft => [
                                    'Action required — review your draft and submit it when ready.',
                                    'Continue draft',
                                ],

                                App\Enums\RequestStatus::ReturnedForRevision => [
                                    'Action required — review the remarks and update your request.',
                                    'Revise request',
                                ],

                                App\Enums\RequestStatus::FinalApprovedAwaitingDownload => [
                                    'Action required — download the approved letter before the deadline.',
                                    'Open documents',
                                ],

                                App\Enums\RequestStatus::ApprovedReadyForRelease => [
                                    'Follow the release instructions from SPMU.',
                                    'View release status',
                                ],

                                App\Enums\RequestStatus::UnderSpmu => [
                                    'No action required — waiting for SPMU review.',
                                    'View progress',
                                ],

                                App\Enums\RequestStatus::UnderGsu => [
                                    'No action required — waiting for GSU review.',
                                    'View progress',
                                ],

                                App\Enums\RequestStatus::UnderVpaf => [
                                    'No action required — waiting for VPAF review.',
                                    'View progress',
                                ],

                                App\Enums\RequestStatus::Rejected => [
                                    'Review the decision and recorded remarks.',
                                    'View decision',
                                ],

                                App\Enums\RequestStatus::Cancelled => [
                                    'This request was cancelled.',
                                    'View record',
                                ],

                                App\Enums\RequestStatus::Expired => [
                                    'The approved-letter download period expired.',
                                    'View record',
                                ],

                                default => [
                                    'Your request is moving through the approval process.',
                                    'View progress',
                                ],
                            },
                        };


                        /*
                        |--------------------------------------------------------------------------
                        | Action required styling
                        |--------------------------------------------------------------------------
                        |
                        | Released/on-custody items are informational rather
                        | than request-correction actions.
                        |
                        */

                        $requiresAction =
                            ! $hasPostReleaseCustody
                            && in_array(
                                $record->status,
                                [
                                    App\Enums\RequestStatus::Draft,
                                    App\Enums\RequestStatus::ReturnedForRevision,
                                    App\Enums\RequestStatus::FinalApprovedAwaitingDownload,
                                ],
                                true
                            );


                        /*
                        |--------------------------------------------------------------------------
                        | Destination URL
                        |--------------------------------------------------------------------------
                        |
                        | When a Borrower's Slip/custody record exists, use it
                        | for release and custody-related actions.
                        |
                        */

                        $useCustodyPage =
                            $custody
                            && in_array(
                                $custodyStatus,
                                [
                                    'PREPARING_RELEASE',
                                    'ACTIVE',
                                    'PARTIALLY_RETURNED',
                                    'OVERDUE',
                                    'EARLY_RETURN',
                                    'INCIDENT_OPEN',
                                    'OBLIGATION_OPEN',
                                    'CLOSED',
                                ],
                                true
                            );


                        $taskUrl =
                            $useCustodyPage
                                ? route(
                                    'custody.show',
                                    $custody
                                )
                                : route(
                                    'requests.show',
                                    $record
                                );


                        /*
                        |--------------------------------------------------------------------------
                        | Task date
                        |--------------------------------------------------------------------------
                        |
                        | Before release show the requested "needed" date.
                        |
                        | Once custody exists, show the actual return deadline.
                        |
                        */

                        $taskDateLabel =
                            $useCustodyPage
                                ? 'Return due'
                                : 'Needed';


                        $taskDate =
                            $useCustodyPage
                                ? optional(
                                    $custody?->due_at
                                )->format('d M Y')
                                : optional(
                                    $record->currentVersion?->needed_from
                                )->format('d M Y');


                        $taskDate =
                            $taskDate
                            ?: 'date pending';
                    @endphp


                    <article
                        class="
                            dashboard-task-row
                            {{ $requiresAction ? 'is-action-required' : '' }}
                        "
                    >

                        <a
                            class="
                                dashboard-task-link
                                ui-pressable
                            "
                            href="{{ $taskUrl }}"
                        >

                            <span class="dashboard-task-copy">


                                {{-- Purpose --}}
                                <span class="dashboard-task-purpose">

                                    {{
                                        $record->currentVersion?->purpose_event
                                            ?: 'Borrowing request'
                                    }}

                                </span>


                                {{-- Request reference + effective status --}}
                                <span class="dashboard-task-heading">

                                    <span class="record-reference">
                                        {{ $record->request_no }}
                                    </span>

                                    <x-status-badge
                                        :status="$effectiveStatus"
                                        :label="$effectiveStatusLabel"
                                    />

                                </span>


                                {{-- Next-step explanation --}}
                                <small>
                                    {{ $taskMessage }}
                                </small>


                                {{-- Footer --}}
                                <span class="dashboard-task-footer">

                                    <span class="dashboard-task-date">

                                        {{ $taskDateLabel }}
                                        {{ $taskDate }}

                                    </span>

                                    <span
                                        class="
                                            dashboard-task-action
                                            {{ $requiresAction ? 'is-required' : '' }}
                                        "
                                    >
                                        {{ $taskAction }}
                                    </span>

                                </span>

                            </span>


                            <x-icon name="chevron-right" />

                        </a>

                    </article>


                {{-- ========================================= --}}
                {{-- ICTU TASK                                 --}}
                {{-- ========================================= --}}

                @elseif($workspace === 'ICTU')

                    <article>

                        <div>

                            <strong>
                                {{ $record->full_name }}
                            </strong>

                            <small>
                                {{ $record->email }}
                                ·
                                {{ $record->organizationalUnit?->unit_name }}
                            </small>

                        </div>

                        <x-status-badge
                            :status="$record->access_classification?->value ?? 'Unclassified'"
                        />

                    </article>


                {{-- ========================================= --}}
                {{-- SPMU / GSU / VPAF TASK                    --}}
                {{-- ========================================= --}}

                @else

                    @php
                        /*
                         * Also keep operational custody-aware status available
                         * to other authorized dashboards when a record already
                         * has custody.
                         */

                        $recordCustody =
                            $record->custody;

                        $recordCustodyStatus =
                            $recordCustody?->status;


                        $recordDisplayStatus = match($recordCustodyStatus) {

                            'ACTIVE' =>
                                'ACTIVE',

                            'PARTIALLY_RETURNED' =>
                                'PARTIALLY_RETURNED',

                            'OVERDUE' =>
                                'OVERDUE',

                            'EARLY_RETURN' =>
                                'EARLY_RETURN',

                            'INCIDENT_OPEN' =>
                                'INCIDENT_OPEN',

                            'OBLIGATION_OPEN' =>
                                'OBLIGATION_OPEN',

                            'CLOSED' =>
                                'CLOSED',

                            default =>
                                $record->status,
                        };


                        $recordDisplayLabel = match($recordCustodyStatus) {

                            'ACTIVE' =>
                                'Released',

                            'PARTIALLY_RETURNED' =>
                                'Partially Returned',

                            'OVERDUE' =>
                                'Overdue',

                            'EARLY_RETURN' =>
                                'Early Return',

                            'INCIDENT_OPEN' =>
                                'Incident Open',

                            'OBLIGATION_OPEN' =>
                                'Obligation Open',

                            'CLOSED' =>
                                'Returned',

                            default =>
                                $record->status->label(),
                        };
                    @endphp


                    <article>

                        <div>

                            <a
                                class="
                                    ui-pressable
                                    dashboard-record-link
                                "
                                href="{{ route('requests.show', $record) }}"
                            >

                                <strong>
                                    {{ $record->request_no }}
                                </strong>

                            </a>

                            <small>
                                {{
                                    $record->borrower?->full_name
                                    .' · '
                                    .$record->currentVersion?->purpose_event
                                }}
                            </small>

                        </div>

                        <x-status-badge
                            :status="$recordDisplayStatus"
                            :label="$recordDisplayLabel"
                        />

                    </article>

                @endif


            @empty

                <div class="empty-state">

                    <div>

                        <strong>
                            You are up to date
                        </strong>

                        <br>

                        <span>
                            Nothing requires your attention right now.
                        </span>

                    </div>

                </div>

            @endforelse

        </div>

    </article>


    {{-- ===================================================== --}}
    {{-- QUICK ACTIONS                                         --}}
    {{-- ===================================================== --}}

    <aside class="card quick-card">

        <p class="eyebrow">
            Shortcuts
        </p>

        <h2>
            Common tasks
        </h2>


        <nav
            class="
                quick-actions
                {{ $workspace === 'BORROWER' ? 'borrower-quick-actions' : '' }}
            "
            aria-label="Quick actions"
        >


            {{-- ============================================= --}}
            {{-- BORROWER QUICK ACTIONS                        --}}
            {{-- ============================================= --}}

            @if($workspace === 'BORROWER')

                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('requests.create') }}"
                >

                    <span
                        class="quick-action-icon"
                        aria-hidden="true"
                    >
                        <x-icon
                            name="plus"
                            size="18"
                        />
                    </span>

                    <span>

                        <strong>
                            Create a borrowing request
                        </strong>

                        <small>
                            Choose items and borrowing dates.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('requests.index') }}"
                >

                    <span
                        class="quick-action-icon"
                        aria-hidden="true"
                    >
                        <x-icon
                            name="requests"
                            size="18"
                        />
                    </span>

                    <span>

                        <strong>
                            View my requests
                        </strong>

                        <small>
                            Track approvals, remarks, and documents.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('custody.index') }}"
                >

                    <span
                        class="quick-action-icon"
                        aria-hidden="true"
                    >
                        <x-icon
                            name="custody"
                            size="18"
                        />
                    </span>

                    <span>

                        <strong>
                            View active borrowings
                        </strong>

                        <small>
                            Check issued items and return deadlines.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('inventory.index') }}"
                >

                    <span
                        class="quick-action-icon"
                        aria-hidden="true"
                    >
                        <x-icon
                            name="inventory"
                            size="18"
                        />
                    </span>

                    <span>

                        <strong>
                            Browse available items
                        </strong>

                        <small>
                            Review inventory available to request.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('accountability.index') }}"
                >

                    <span
                        class="quick-action-icon"
                        aria-hidden="true"
                    >
                        <x-icon
                            name="accountability"
                            size="18"
                        />
                    </span>

                    <span>

                        <strong>
                            View accountability
                        </strong>

                        <small>
                            Review obligations linked to your account.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


            {{-- ============================================= --}}
            {{-- SPMU QUICK ACTIONS                            --}}
            {{-- ============================================= --}}

            @elseif($workspace === 'SPMU')

                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('approvals.index') }}"
                >

                    <span>

                        <strong>
                            Review requests
                        </strong>

                        <small>
                            Open the SPMU approval queue.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('custody.index') }}"
                >

                    <span>

                        <strong>
                            Process a release or return
                        </strong>

                        <small>
                            Record quantities, signatures, and inspection.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('inventory.index') }}"
                >

                    <span>

                        <strong>
                            Update inventory
                        </strong>

                        <small>
                            Maintain descriptions, quantities, and availability.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


            {{-- ============================================= --}}
            {{-- GSU / VPAF QUICK ACTIONS                      --}}
            {{-- ============================================= --}}

            @elseif(
                in_array(
                    $workspace,
                    [
                        'GSU',
                        'VPAF'
                    ],
                    true
                )
            )

                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('approvals.index') }}"
                >

                    <span>

                        <strong>
                            Open approval queue
                        </strong>

                        <small>
                            Review requests waiting for your decision.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('calendar.index') }}"
                >

                    <span>

                        <strong>
                            View borrowing calendar
                        </strong>

                        <small>
                            See confirmed schedules and deadlines.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


            {{-- ============================================= --}}
            {{-- ICTU QUICK ACTIONS                            --}}
            {{-- ============================================= --}}

            @else

                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('administration.users.index') }}"
                >

                    <span>

                        <strong>
                            Manage user accounts
                        </strong>

                        <small>
                            Register accounts and assign access.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('administration.delegations.index') }}"
                >

                    <span>

                        <strong>
                            Manage delegated approvers
                        </strong>

                        <small>
                            Record temporary approval authority.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>


                <a
                    class="
                        interactive
                        ui-pressable
                    "
                    href="{{ route('reports.audit') }}"
                >

                    <span>

                        <strong>
                            Review the audit trail
                        </strong>

                        <small>
                            See technical and user actions.
                        </small>

                    </span>

                    <x-icon name="chevron-right" />

                </a>

            @endif

        </nav>

    </aside>

</section>


{{-- ========================================================= --}}
{{-- UPCOMING CUSTODY DEADLINES                                --}}
{{-- ========================================================= --}}

@if($workspace !== 'ICTU')

    <section class="content-area dashboard-deadlines">

        <article class="card">

            <div class="card-header">

                <div>

                    <p class="eyebrow">
                        Calendar preview
                    </p>

                    <h2>
                        {{
                            $workspace === 'BORROWER'
                                ? 'My upcoming deadlines'
                                : 'Upcoming custody deadlines'
                        }}
                    </h2>

                </div>


                <a
                    class="dashboard-view-all"
                    href="{{ route('calendar.index') }}"
                >
                    View calendar

                    <x-icon
                        name="chevron-right"
                        size="16"
                    />
                </a>

            </div>


            <div class="deadline-list">

                @forelse($nextCustodies as $custody)

                    <a
                        class="
                            deadline-event
                            interactive
                            ui-pressable
                            {{
                                $custody->status === 'OVERDUE'
                                    ? 'is-overdue'
                                    : ''
                            }}
                        "
                        href="{{ route('custody.show', $custody) }}"
                    >

                        <time
                            class="deadline-date"
                            datetime="{{ $custody->due_at->toIso8601String() }}"
                        >

                            <span>
                                {{ $custody->due_at->format('M') }}
                            </span>

                            <strong>
                                {{ $custody->due_at->format('d') }}
                            </strong>

                            <small>
                                {{ $custody->due_at->format('Y') }}
                            </small>

                        </time>


                        <span class="deadline-event-copy">

                            <strong>
                                {{ $custody->custody_no }}
                            </strong>

                            <small>
                                {{
                                    $workspace === 'BORROWER'
                                        ? $custody->request->request_no
                                        : $custody->borrower->full_name
                                }}
                            </small>

                            <span>
                                Return deadline ·
                                {{ $custody->due_at->format('g:i A') }}
                            </span>

                        </span>


                        <span class="deadline-event-status">

                            <x-status-badge
                                :status="$custody->status"
                                :label="match($custody->status) {
                                    'ACTIVE' => 'Released',
                                    'PARTIALLY_RETURNED' => 'Partially Returned',
                                    'OVERDUE' => 'Overdue',
                                    'EARLY_RETURN' => 'Early Return',
                                    'INCIDENT_OPEN' => 'Incident Open',
                                    'OBLIGATION_OPEN' => 'Obligation Open',
                                    default => null,
                                }"
                            />

                            <span>
                                View borrowing

                                <x-icon
                                    name="chevron-right"
                                    size="16"
                                />
                            </span>

                        </span>

                    </a>

                @empty

                    <div class="empty-state">

                        <div>

                            <strong>
                                No upcoming returns
                            </strong>

                            <br>

                            <span>
                                Active return deadlines will appear here.
                            </span>

                        </div>

                    </div>

                @endforelse

            </div>

        </article>

    </section>

@endif

@endsection