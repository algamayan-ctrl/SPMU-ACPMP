@extends('layouts.app', [
    'title' => session('active_workspace') === 'BORROWER'
        ? 'My Requests'
        : (
            auth()->user()->access_classification === App\Enums\AccessClassification::SpmuOfficer
                ? 'Approved Requests'
                : 'Request Records'
        )
])

@section('content')

@php
    $isBorrower =
        session('active_workspace') === 'BORROWER';

    $isSpmuOfficer =
        session('active_workspace') === 'SPMU'
        && auth()->user()->access_classification
            === App\Enums\AccessClassification::SpmuOfficer
        && auth()->user()->activeDelegationFor('SPMU') === null;

    $isSpmuHead =
        session('active_workspace') === 'SPMU'
        && auth()->user()->access_classification
            === App\Enums\AccessClassification::SpmuHead;

    $pageTitle = $isBorrower
        ? 'My Requests'
        : (
            $isSpmuOfficer
                ? 'Approved Requests'
                : 'Request Records'
        );

    $pageCopy = $isBorrower
        ? 'Track each request and see the next action you need to take.'
        : (
            $isSpmuOfficer
                ? 'View approved requests and their current release or return status.'
                : 'Review borrowing request records and their current transaction status.'
        );
@endphp


<section class="page-heading">

    <div>
        <p class="eyebrow">
            Borrowing requests
        </p>

        <h1>
            {{ $pageTitle }}
        </h1>

        <p>
            {{ $pageCopy }}
        </p>
    </div>


    @if($isBorrower)
        <a
            class="button primary ui-pressable"
            href="{{ route('requests.create') }}"
        >
            New Request
        </a>
    @endif

</section>


<section class="content-area">

    <div class="table-wrap">

        <table>

            <thead>
                <tr>
                    <th>Request</th>
                    <th>Purpose</th>
                    <th>Schedule</th>
                    <th>Return</th>
                    <th>Status</th>

                    @if($isBorrower)
                        <th>Next Action</th>
                    @endif

                    <th></th>
                </tr>
            </thead>


            <tbody>

            @forelse($requests as $record)

                @php
                    $version = $record->currentVersion;
                    $custody = $record->custody;


                    /*
                    |--------------------------------------------------------------------------
                    | REQUEST STATUS
                    |--------------------------------------------------------------------------
                    */

                    $requestStatus = $record->status
                        instanceof App\Enums\RequestStatus
                            ? $record->status
                            : App\Enums\RequestStatus::tryFrom(
                                (string) $record->status
                            );

                    $requestStatusValue =
                        $requestStatus?->value
                        ?: strtoupper(
                            (string) $record->status
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | CUSTODY STATUS
                    |--------------------------------------------------------------------------
                    |
                    | Once a custody transaction exists, its current state becomes the
                    | operational source of truth.
                    |
                    | Example:
                    |
                    | Request:
                    | APPROVED_READY_FOR_RELEASE
                    |
                    | Custody:
                    | CLOSED
                    |
                    | Display:
                    | COMPLETED
                    |
                    */

                    $custodyStatus = null;

                    if ($custody) {
                        $custodyStatus = $custody->status instanceof \BackedEnum
                            ? $custody->status->value
                            : strtoupper(
                                (string) $custody->status
                            );
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | DISPLAY STATUS
                    |--------------------------------------------------------------------------
                    */

                    $displayStatus = $requestStatusValue;
                    $statusLabel = null;


                    /*
                    |--------------------------------------------------------------------------
                    | CUSTODY OVERRIDES REQUEST STATUS
                    |--------------------------------------------------------------------------
                    |
                    | IMPORTANT:
                    |
                    | This applies to Borrower, SPMU Head, and SPMU Action Officer.
                    | We do NOT allow the old request approval state to leave stale
                    | "Ready for Release" labels after release/return/completion.
                    |
                    */

                    if ($custody) {

                        $hasPickupSchedule =
                            (bool) (
                                $custody->scheduled_release_at
                                && ! $custody->pickup_expired_at
                            );

                        $preparationComplete =
                            (bool) $custody->prepared_at;

                        $released =
                            (bool) $custody->released_at;


                        [
                            $displayStatus,
                            $statusLabel
                        ] = match (true) {

                            /*
                             * FINAL COMPLETED TRANSACTION
                             */
                            $custodyStatus === 'CLOSED'
                                => [
                                    'CLOSED',
                                    'Completed',
                                ],


                            /*
                             * ACCOUNTABILITY / UNRESOLVED OBLIGATION
                             */
                            $custodyStatus === 'OBLIGATION_OPEN'
                                => [
                                    'OBLIGATION_OPEN',
                                    'Outstanding Obligation',
                                ],


                            /*
                             * RETURN CURRENTLY BEING PROCESSED
                             */
                            $custodyStatus === 'RETURN_PROCESSING'
                                => [
                                    'RETURN_PROCESSING',
                                    'Return Processing',
                                ],


                            /*
                             * OVERDUE
                             */
                            $custodyStatus === 'OVERDUE'
                                => [
                                    'OVERDUE',
                                    'Overdue',
                                ],


                            /*
                             * PHYSICALLY RELEASED / ON BORROWER CUSTODY
                             */
                            $custodyStatus === 'ACTIVE'
                                && $released
                                => [
                                    'BORROWED',
                                    'Items Released / On Custody',
                                ],


                            /*
                             * PICKUP WINDOW EXPIRED
                             */
                            $custodyStatus === 'PREPARING_RELEASE'
                                && (bool) $custody->pickup_expired_at
                                => [
                                    'PREPARING_RELEASE',
                                    'Pickup Window Expired',
                                ],


                            /*
                             * PREPARATION COMPLETE + PICKUP SCHEDULED
                             */
                            $custodyStatus === 'PREPARING_RELEASE'
                                && $preparationComplete
                                && $hasPickupSchedule
                                => [
                                    'READY_FOR_RELEASE',
                                    'Ready for Release',
                                ],


                            /*
                             * PICKUP SCHEDULED BUT ITEM PREPARATION ONGOING
                             */
                            $custodyStatus === 'PREPARING_RELEASE'
                                && $hasPickupSchedule
                                && ! $preparationComplete
                                => [
                                    'PREPARING_RELEASE',
                                    'Preparing for Release',
                                ],


                            /*
                             * APPROVED / RESERVED BUT NO PICKUP SCHEDULE
                             */
                            $custodyStatus === 'PREPARING_RELEASE'
                                && ! $hasPickupSchedule
                                => [
                                    'PREPARING_RELEASE',
                                    'Waiting for Pickup Schedule',
                                ],


                            /*
                             * FALLBACK
                             */
                            default
                                => [
                                    $custodyStatus
                                        ?: $requestStatusValue,

                                    null,
                                ],
                        };
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | REQUEST-ONLY STATUS
                    |--------------------------------------------------------------------------
                    |
                    | No custody transaction yet.
                    |
                    */

                    elseif (
                        $isSpmuHead
                        && $requestStatus === App\Enums\RequestStatus::UnderSpmu
                    ) {
                        $statusLabel = 'For Head Approval';
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | BORROWER NEXT ACTION
                    |--------------------------------------------------------------------------
                    */

                    $nextAction = match (true) {

                        /*
                         * COMPLETED
                         */
                        $custodyStatus === 'CLOSED'
                            => 'No action required.',


                        /*
                         * OBLIGATION OPEN
                         */
                        $custodyStatus === 'OBLIGATION_OPEN'
                            => 'Review My Obligations for the outstanding requirement.',


                        /*
                         * RETURN PROCESSING
                         */
                        $custodyStatus === 'RETURN_PROCESSING'
                            => 'Return processing is ongoing. Check My Borrowings for the latest status.',


                        /*
                         * OVERDUE
                         */
                        $custodyStatus === 'OVERDUE'
                            => 'This borrowing is overdue. Check My Borrowings and return the outstanding items.',


                        /*
                         * RELEASED / ON CUSTODY
                         */
                        $custodyStatus === 'ACTIVE'
                            && (bool) $custody?->released_at
                            => 'Return the borrowed items by the required return date.',


                        /*
                         * PICKUP WINDOW EXPIRED
                         */
                        $custodyStatus === 'PREPARING_RELEASE'
                            && (bool) $custody?->pickup_expired_at
                            => 'Wait for SPMU to provide a new pickup schedule.',


                        /*
                         * READY FOR RELEASE
                         */
                        $custodyStatus === 'PREPARING_RELEASE'
                            && (bool) $custody?->prepared_at
                            && (bool) $custody?->scheduled_release_at
                            => 'Pick up your approved items at SPMU on the scheduled date and time.',


                        /*
                         * ITEM PREPARATION
                         */
                        $custodyStatus === 'PREPARING_RELEASE'
                            && (bool) $custody?->scheduled_release_at
                            && ! $custody?->prepared_at
                            => 'No action yet. SPMU is preparing your approved items.',


                        /*
                         * WAITING FOR PICKUP SCHEDULE
                         */
                        $custodyStatus === 'PREPARING_RELEASE'
                            && ! $custody?->scheduled_release_at
                            => 'No action yet. Wait for SPMU to schedule your pickup.',


                        /*
                         * DRAFT
                         */
                        $requestStatus === App\Enums\RequestStatus::Draft
                            => 'Complete the request and required documents before submission.',


                        /*
                         * RETURNED FOR REVISION
                         */
                        $requestStatus === App\Enums\RequestStatus::ReturnedForRevision
                            => 'Review the SPMU remarks, correct the request, and resubmit it.',


                        /*
                         * UNDER SPMU REVIEW
                         */
                        $requestStatus === App\Enums\RequestStatus::UnderSpmu
                            => 'No action required while SPMU reviews your request.',


                        /*
                         * APPROVED BUT CUSTODY HAS NOT PROGRESSED YET
                         */
                        $requestStatus === App\Enums\RequestStatus::ApprovedReadyForRelease
                            => 'No action yet. Wait for SPMU to schedule your pickup.',


                        /*
                         * REJECTED
                         */
                        $requestStatus === App\Enums\RequestStatus::Rejected
                            => 'No action required. This request was rejected.',


                        /*
                         * CANCELLED
                         */
                        $requestStatus === App\Enums\RequestStatus::Cancelled
                            => 'No action required. This request was cancelled.',


                        /*
                         * EXPIRED
                         */
                        $requestStatus === App\Enums\RequestStatus::Expired
                            => 'No action required. This request expired.',


                        /*
                         * FALLBACK
                         */
                        default
                            => 'Open the request to review its current status.',
                    };
                @endphp


                <tr>

                    {{-- REQUEST --}}
                    <td>
                        <strong>
                            {{ $record->request_no }}
                        </strong>

                        @unless($isBorrower)
                            <small>
                                {{ $record->borrower?->full_name }}
                            </small>
                        @endunless
                    </td>


                    {{-- PURPOSE --}}
                    <td>
                        {{ $version?->purpose_event ?: '—' }}
                    </td>


                    {{-- SCHEDULE --}}
                    <td>
                        {{
                            optional(
                                $version?->schedule_date
                                ?: $version?->needed_from
                            )->format('d M Y') ?: '—'
                        }}
                    </td>


                    {{-- RETURN --}}
                    <td>
                        {{
                            optional(
                                $version?->return_date
                                ?: $version?->return_due_at
                            )->format('d M Y') ?: '—'
                        }}
                    </td>


                    {{-- STATUS --}}
                    <td>
                        <x-status-badge
                            :status="$displayStatus"
                            :label="$statusLabel"
                        />
                    </td>


                    {{-- BORROWER NEXT ACTION --}}
                    @if($isBorrower)
                        <td>
                            <small>
                                {{ $nextAction }}
                            </small>
                        </td>
                    @endif


                    {{-- VIEW --}}
                    <td>
                        <a
                            class="table-action"
                            href="{{ route('requests.show', $record) }}"
                        >
                            View
                        </a>
                    </td>

                </tr>


            @empty

                <tr>
                    <td
                        colspan="{{ $isBorrower ? 7 : 6 }}"
                        class="empty-state"
                    >
                        {{
                            $isSpmuOfficer
                                ? 'No approved requests found.'
                                : 'No borrowing requests found.'
                        }}
                    </td>
                </tr>

            @endforelse

            </tbody>

        </table>

    </div>

</section>

@endsection