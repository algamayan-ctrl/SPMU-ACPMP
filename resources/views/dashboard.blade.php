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
        'SPMU' => route('approvals.index'),
        'ICTU' => route('administration.users.index'),
        default => route('dashboard'),
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
                Welcome, {{ $firstName }}
            </h1>

            <p>
                Track your requests, active borrowings, return deadlines, and accountability records in one place.
            </p>

        @else

            <h1>
                Welcome, {{ $firstName }}
            </h1>

            <p>
                {{
                    match($workspace) {
                        'SPMU' =>
                            'Review borrowing requests, prepare approved items for release, process returns, and monitor inventory operations.',

                        'ICTU' =>
                            'Manage user access, temporary approvers, settings, and system records.',

                        default =>
                            'Open the functions assigned to your account.',
                    }
                }}
            </p>

        @endif

    </div>

    @if($workspace === 'BORROWER')

        <a
            class="button primary ui-pressable"
            href="{{ route('requests.create') }}"
        >
            <x-icon
                name="plus"
                size="16"
            />

            New borrowing request
        </a>

    @endif

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
                    {{
                        match($workspace) {
                            'SPMU' => 'SPMU operations',
                            'ICTU' => 'ICTU administration',
                            default => 'Your task list',
                        }
                    }}
                </p>

                <h2>
                    {{
                        $workspace === 'ICTU'
                            ? 'Recently added accounts'
                            : (
                                $workspace === 'SPMU'
                                    ? 'Operational tasks requiring attention'
                                    : 'What needs your attention'
                            )
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

                            default => match($record->status) {

                                App\Enums\RequestStatus::Draft =>
                                    'Draft',

                                App\Enums\RequestStatus::ReturnedForRevision =>
                                    'Needs Revision',

                                /*
                                 * Legacy compatibility only. The revised borrower UI
                                 * no longer requires an approved-letter download step.
                                 */
                                App\Enums\RequestStatus::FinalApprovedAwaitingDownload =>
                                    'Approved',

                                App\Enums\RequestStatus::ApprovedReadyForRelease =>
                                    'Ready for Release',

                                App\Enums\RequestStatus::UnderSpmu =>
                                    'Under SPMU Review',

                                /*
                                 * Hide retired approval-stage names from the borrower UI
                                 * while older records are still present in the database.
                                 */
                                App\Enums\RequestStatus::UnderGsu,
                                App\Enums\RequestStatus::UnderVpaf =>
                                    'Under Review',

                                App\Enums\RequestStatus::Rejected =>
                                    'Rejected',

                                App\Enums\RequestStatus::Cancelled =>
                                    'Cancelled',

                                App\Enums\RequestStatus::Expired =>
                                    'Closed',

                                default =>
                                    $record->status?->label() ?? 'Status unavailable',
                            },
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
                                    'Action required â€” complete the required documents, then submit your request to SPMU.',
                                    'Continue request',
                                ],

                                App\Enums\RequestStatus::ReturnedForRevision => [
                                    'Action required â€” review the SPMU remarks, update the request, and resubmit when ready.',
                                    'Revise request',
                                ],

                                /*
                                 * Legacy compatibility only. Treat older records in this
                                 * state as approved without showing a download requirement.
                                 */
                                App\Enums\RequestStatus::FinalApprovedAwaitingDownload => [
                                    'Your request has been approved. SPMU will continue the release preparation.',
                                    'View approval',
                                ],

                                App\Enums\RequestStatus::ApprovedReadyForRelease => [
                                    'Approved by SPMU. Review the release status and pickup instructions.',
                                    'View release status',
                                ],

                                App\Enums\RequestStatus::UnderSpmu => [
                                    'Submitted successfully. Waiting for SPMU review.',
                                    'View progress',
                                ],

                                App\Enums\RequestStatus::UnderGsu,
                                App\Enums\RequestStatus::UnderVpaf => [
                                    'Your submitted request is still under review. No borrower action is required.',
                                    'View progress',
                                ],

                                App\Enums\RequestStatus::Rejected => [
                                    'SPMU rejected this request. Review the decision and recorded remarks.',
                                    'View decision',
                                ],

                                App\Enums\RequestStatus::Cancelled => [
                                    'This request was cancelled.',
                                    'View record',
                                ],

                                App\Enums\RequestStatus::Expired => [
                                    'This request is no longer active. Open the record for details.',
                                    'View record',
                                ],

                                default => [
                                    'Open the request to review its current status and next step.',
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
                                &middot;
                                {{ $record->organizationalUnit?->unit_name }}
                            </small>

                        </div>

                        <x-status-badge
                            :status="$record->access_classification?->value ?? 'Unclassified'"
                        />

                    </article>


                {{-- ========================================= --}}
                {{-- SPMU TASK                                  --}}
                {{-- ========================================= --}}

                @elseif($workspace === 'SPMU')

                    @php
                        $recordCustody = $record->custody;
                        $recordCustodyStatus = $recordCustody?->status;

                        $recordDisplayStatus = match($recordCustodyStatus) {
                            'PREPARING_RELEASE' => 'PREPARING_RELEASE',
                            'ACTIVE' => 'ACTIVE',
                            'PARTIALLY_RETURNED' => 'PARTIALLY_RETURNED',
                            'OVERDUE' => 'OVERDUE',
                            'EARLY_RETURN' => 'EARLY_RETURN',
                            'INCIDENT_OPEN' => 'INCIDENT_OPEN',
                            'OBLIGATION_OPEN' => 'OBLIGATION_OPEN',
                            'CLOSED' => 'CLOSED',
                            default => $record->status,
                        };

                        $recordDisplayLabel = match($recordCustodyStatus) {
                            'PREPARING_RELEASE' => 'Preparing for Release',
                            'ACTIVE' => 'On Custody',
                            'PARTIALLY_RETURNED' => 'Partially Returned',
                            'OVERDUE' => 'Overdue',
                            'EARLY_RETURN' => 'Early Return',
                            'INCIDENT_OPEN' => 'Incident Open',
                            'OBLIGATION_OPEN' => 'Obligation Open',
                            'CLOSED' => 'Returned',
                            default => match($record->status) {
                                App\Enums\RequestStatus::UnderSpmu => 'For SPMU Review',
                                App\Enums\RequestStatus::ApprovedReadyForRelease => 'Approved / Reserved',
                                App\Enums\RequestStatus::ReturnedForRevision => 'Returned for Revision',
                                App\Enums\RequestStatus::Rejected => 'Rejected',
                                App\Enums\RequestStatus::Cancelled => 'Cancelled',
                                App\Enums\RequestStatus::Expired => 'Closed',
                                App\Enums\RequestStatus::FinalApprovedAwaitingDownload => 'Approved',
                                App\Enums\RequestStatus::UnderGsu,
                                App\Enums\RequestStatus::UnderVpaf => 'Legacy Review',
                                default => $record->status?->label() ?? 'Status unavailable',
                            },
                        };

                        [$spmuTaskMessage, $spmuTaskAction] = match($recordCustodyStatus) {
                            'PREPARING_RELEASE' => [
                                'Approved quantities are reserved. Prepare and verify the items for physical release.',
                                'Open release preparation',
                            ],

                            'ACTIVE' => [
                                'The items have been physically released and are currently under borrower custody.',
                                'View custody',
                            ],

                            'PARTIALLY_RETURNED' => [
                                'A partial return has been recorded. Continue processing the remaining outstanding quantities.',
                                'Continue return',
                            ],

                            'OVERDUE' => [
                                'The return deadline has passed. Review the custody record and process the required return or accountability action.',
                                'Review overdue case',
                            ],

                            'EARLY_RETURN' => [
                                'The borrower submitted an early-return notice. Coordinate physical handover and inspection.',
                                'Process early return',
                            ],

                            'INCIDENT_OPEN',
                            'OBLIGATION_OPEN' => [
                                'An accountability issue remains open for this borrowing.',
                                'Review obligation',
                            ],

                            'CLOSED' => [
                                'The borrowing and recorded return requirements are complete.',
                                'View completed record',
                            ],

                            default => match($record->status) {
                                App\Enums\RequestStatus::UnderSpmu => [
                                    'Review the submitted request, supporting documents, requested quantities, and availability.',
                                    'Review request',
                                ],

                                App\Enums\RequestStatus::ApprovedReadyForRelease,
                                App\Enums\RequestStatus::FinalApprovedAwaitingDownload => [
                                    'SPMU approval has reserved the approved quantities. Continue with release preparation.',
                                    'Prepare release',
                                ],

                                App\Enums\RequestStatus::ReturnedForRevision => [
                                    'This request was returned to the borrower for revision.',
                                    'View request',
                                ],

                                App\Enums\RequestStatus::Rejected => [
                                    'This request has been rejected. The recorded decision remains available for reference.',
                                    'View decision',
                                ],

                                App\Enums\RequestStatus::Cancelled,
                                App\Enums\RequestStatus::Expired => [
                                    'This request is no longer active.',
                                    'View record',
                                ],

                                App\Enums\RequestStatus::UnderGsu,
                                App\Enums\RequestStatus::UnderVpaf => [
                                    'This is a legacy workflow record. Review it for migration or closeout as applicable.',
                                    'Review legacy record',
                                ],

                                default => [
                                    'Open the request to review its current operational status.',
                                    'View request',
                                ],
                            },
                        };

                        $spmuUseCustodyPage =
                            $recordCustody
                            && in_array(
                                $recordCustodyStatus,
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

                        $spmuTaskUrl = $spmuUseCustodyPage
                            ? route('custody.show', $recordCustody)
                            : route('requests.show', $record);

                        $spmuTaskDateLabel = $spmuUseCustodyPage
                            ? 'Return due'
                            : 'Needed';

                        $spmuTaskDate = $spmuUseCustodyPage
                            ? optional($recordCustody?->due_at)->format('d M Y')
                            : optional($record->currentVersion?->needed_from)->format('d M Y');

                        $spmuTaskDate = $spmuTaskDate ?: 'date pending';
                    @endphp

                    <article class="dashboard-task-row">

                        <a
                            class="dashboard-task-link ui-pressable"
                            href="{{ $spmuTaskUrl }}"
                        >
                            <span class="dashboard-task-copy">

                                <span class="dashboard-task-purpose">
                                    {{
                                        $record->currentVersion?->purpose_event
                                            ?: 'Borrowing request'
                                    }}
                                </span>

                                <span class="dashboard-task-heading">

                                    <span class="record-reference">
                                        {{ $record->request_no }}
                                    </span>

                                    <x-status-badge
                                        :status="$recordDisplayStatus"
                                        :label="$recordDisplayLabel"
                                    />

                                </span>

                                <small>
                                    {{ $record->borrower?->full_name }}
                                    @if($record->borrower?->organizationalUnit?->unit_name)
                                        &middot; {{ $record->borrower->organizationalUnit->unit_name }}
                                    @endif
                                </small>

                                <small>
                                    {{ $spmuTaskMessage }}
                                </small>

                                <span class="dashboard-task-footer">

                                    <span class="dashboard-task-date">
                                        {{ $spmuTaskDateLabel }}
                                        {{ $spmuTaskDate }}
                                    </span>

                                    <span class="dashboard-task-action">
                                        {{ $spmuTaskAction }}
                                    </span>

                                </span>

                            </span>

                            <x-icon name="chevron-right" />
                        </a>

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
                            Enter request details and select the items you need.
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
                            Track SPMU review, remarks, and required documents.
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
                            Check current serviceable quantities before requesting.
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

                    <span class="quick-action-icon" aria-hidden="true">
                        <x-icon name="approval" size="18" />
                    </span>

                    <span>
                        <strong>Review borrowing requests</strong>

                        <small>
                            Review submitted documents, requested quantities,
                            and availability before the SPMU decision.
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

                    <span class="quick-action-icon" aria-hidden="true">
                        <x-icon name="custody" size="18" />
                    </span>

                    <span>
                        <strong>Release and return</strong>

                        <small>
                            Prepare approved items, record physical release,
                            and process returned quantities and condition.
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

                    <span class="quick-action-icon" aria-hidden="true">
                        <x-icon name="inventory" size="18" />
                    </span>

                    <span>
                        <strong>Manage inventory</strong>

                        <small>
                            Review stock, reservations, issued quantities,
                            condition, and item records.
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

                    <span class="quick-action-icon" aria-hidden="true">
                        <x-icon name="accountability" size="18" />
                    </span>

                    <span>
                        <strong>Review accountability</strong>

                        <small>
                            Review overdue cases, incidents, billings,
                            payments, and borrowing restrictions.
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

                    <span class="quick-action-icon" aria-hidden="true">
                        <x-icon name="calendar" size="18" />
                    </span>

                    <span>
                        <strong>View borrowing calendar</strong>

                        <small>
                            Review approved borrowing periods and upcoming
                            return deadlines.
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
                                : (
                                    $workspace === 'SPMU'
                                        ? 'Upcoming return deadlines'
                                        : 'Upcoming custody deadlines'
                                )
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
                                Return deadline &middot;
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
