@extends('layouts.app', ['title' => 'Available Items'])

@section('content')

@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $isSpmu = session('active_workspace') === 'SPMU';
@endphp


{{-- ========================================================= --}}
{{-- PAGE HEADING                                              --}}
{{-- ========================================================= --}}

<section class="page-heading">

    <div>
        <p class="eyebrow">
            {{ $isBorrower ? 'Inventory availability' : 'Inventory monitoring' }}
        </p>

        <h1>
            {{ $isBorrower ? 'Available items' : 'Inventory' }}
        </h1>

        @if(!$isBorrower)
            <p>
                Monitor current physical stock, allocated quantities,
                active custody, condition, and borrowing restrictions.
            </p>
        @endif
    </div>


    @if($isBorrower)

        <a
            class="button primary ui-pressable"
            href="{{ route('requests.create') }}"
        >
            Create borrowing request
        </a>

    @elseif($isSpmu)

        <a
            class="button primary ui-pressable"
            href="{{ route('inventory.create') }}"
        >
            <x-icon name="plus" />
            Add inventory item
        </a>

    @endif

</section>


<section class="content-area">


    {{-- ===================================================== --}}
    {{-- AVAILABILITY DATE FILTER                              --}}
    {{-- ===================================================== --}}

    <form
        method="get"
        class="filter-bar availability-filter"
        aria-label="Check availability for a borrowing period"
    >

        <label>
            Items needed from

            <input
                type="datetime-local"
                name="from"
                value="{{ $from->format('Y-m-d\TH:i') }}"
            >
        </label>


        <label>
            Expected return date

            <input
                type="datetime-local"
                name="to"
                value="{{ $to->format('Y-m-d\TH:i') }}"
            >
        </label>


        <button class="button primary">
            Check availability
        </button>

    </form>


    {{-- ===================================================== --}}
    {{-- BORROWER VIEW                                         --}}
    {{-- ===================================================== --}}

    @if($isBorrower)

        <div
            class="availability-window"
            role="note"
        >
            <strong>
                Selected borrowing period:
                {{ $from->format('d M Y, g:i A') }}
                to
                {{ $to->format('d M Y, g:i A') }}
            </strong>

            <span>
                Physical available shows stock currently in SPMU custody.
                Selected-period availability also considers reservations and active
                borrowings that overlap the dates above.
            </span>
        </div>


        <div class="table-wrap borrower-inventory-table">

            <table>

                <thead>
                    <tr>
                        <th scope="col">
                            Item
                        </th>

                        <th scope="col">
                            Category and unit
                        </th>

                        <th scope="col">
                            Availability
                        </th>

                        <th scope="col">
                            Current status
                        </th>

                        <th scope="col">
                            Use conditions
                        </th>

                        <th scope="col">
                            Condition
                        </th>
                    </tr>
                </thead>


                <tbody>

                    @forelse($items as $item)

                        @php
                            $balance = $balances[$item->id];

                            /*
                             * Date-aware quantity available for the
                             * borrowing period selected above.
                             */
                            $periodAvailable =
                                (float) ($balance['available'] ?? 0);

                            /*
                             * Actual physical stock currently present
                             * and serviceable at SPMU.
                             */
                            $currentAvailable =
                                (float) (
                                    $balance['current_available']
                                    ?? $balance['available']
                                    ?? 0
                                );

                            /*
                             * Reserved but not yet physically released.
                             */
                            $allocated =
                                (float) ($balance['allocated'] ?? 0);

                            /*
                             * Physically released and still outstanding.
                             */
                            $onCustody =
                                (float) ($balance['borrowed'] ?? 0);

                            $total =
                                (float) ($balance['total'] ?? 0);
                        @endphp


                        <tr>


                            {{-- ================================= --}}
                            {{-- ITEM                              --}}
                            {{-- ================================= --}}

                            <td data-label="Item">

                                <strong>
                                    {{ $item->unique_description }}
                                </strong>

                                @if($item->specification)

                                    <small>
                                        {{ $item->specification }}
                                    </small>

                                @endif

                            </td>


                            {{-- ================================= --}}
                            {{-- CATEGORY / UNIT                   --}}
                            {{-- ================================= --}}

                            <td data-label="Category and unit">

                                {{ $item->category->category_name }}

                                <small>
                                    {{ $item->unit->unit_name }}
                                </small>

                            </td>


                            {{-- ================================= --}}
                            {{-- SELECTED-DATE AVAILABILITY        --}}
                            {{-- ================================= --}}

                            <td data-label="Availability">

                                @if(
                                    $item->borrowable
                                    && $item->condition_code === 'SERVICEABLE'
                                )

                                    <strong class="availability-number">
                                        {{ $periodAvailable + 0 }}
                                    </strong>

                                    <small>
                                        of {{ $total + 0 }} total
                                    </small>


                                    @if($periodAvailable > 0)

                                        <x-status-badge
                                            status="AVAILABLE"
                                            label="Available for selected dates"
                                        />

                                    @else

                                        <x-status-badge
                                            status="UNAVAILABLE"
                                            label="Unavailable for selected dates"
                                        />

                                    @endif

                                @else

                                    <x-status-badge
                                        status="UNAVAILABLE"
                                        label="Not available for borrowing"
                                    />

                                @endif

                            </td>


                            {{-- ================================= --}}
                            {{-- CURRENT INVENTORY STATUS          --}}
                            {{-- ================================= --}}

                            <td data-label="Current status">

                                <span class="quantity-pair">

                                    <span>
                                        Available now

                                        <strong>
                                            {{ $currentAvailable + 0 }}
                                        </strong>
                                    </span>


                                    <span>
                                        Allocated

                                        <strong>
                                            {{ $allocated + 0 }}
                                        </strong>
                                    </span>


                                    <span>
                                        On custody

                                        <strong>
                                            {{ $onCustody + 0 }}
                                        </strong>
                                    </span>

                                </span>

                            </td>


                            {{-- ================================= --}}
                            {{-- USE CONDITIONS                    --}}
                            {{-- ================================= --}}

                            <td data-label="Use conditions">

                                <span>
                                    {{
                                        $item->off_campus_allowed
                                            ? 'Off-campus allowed'
                                            : 'On-campus only'
                                    }}
                                </span>


                                @if($item->laundry_required)

                                    <small>
                                        Laundry Form required after use
                                    </small>

                                @endif


                                @if($item->provisional)

                                    <small class="text-warning">
                                        Quantity is still being confirmed
                                    </small>

                                @endif

                            </td>


                            {{-- ================================= --}}
                            {{-- CONDITION                         --}}
                            {{-- ================================= --}}

                            <td data-label="Condition">

                                <x-status-badge
                                    :status="$item->condition_code"
                                />

                            </td>

                        </tr>


                    @empty

                        <tr>

                            <td
                                colspan="6"
                                class="empty-state"
                            >

                                <strong>
                                    No inventory items are available to display.
                                </strong>

                                <span>
                                    Try again later or contact SPMU
                                    if you need assistance.
                                </span>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>


    {{-- ===================================================== --}}
    {{-- SPMU / OPERATIONAL INVENTORY VIEW                     --}}
    {{-- ===================================================== --}}

    @else

        <div
            class="availability-window"
            role="note"
        >

            <strong>
                Current inventory status
            </strong>

            <span>
                Physical availability shows actual serviceable stock currently
                available at SPMU. Allocated quantities are reserved but not yet
                released, while On custody shows quantities physically issued
                to borrowers.
            </span>

        </div>


        <div
            class="
                table-wrap
                operational-table
                inventory-operations-table
            "
        >

            <table>

                <thead>
                    <tr>

                        <th scope="col">
                            Item
                        </th>

                        <th scope="col">
                            Availability
                        </th>

                        <th scope="col">
                            Committed quantities
                        </th>

                        <th scope="col">
                            Condition
                        </th>

                        <th scope="col">
                            Use conditions
                        </th>

                        <th scope="col">
                            <span class="visually-hidden">
                                Actions
                            </span>
                        </th>

                    </tr>
                </thead>


                <tbody>

                    @forelse($items as $item)

                        @php
                            $balance = $balances[$item->id];

                            /*
                             * CURRENT physical availability.
                             *
                             * Allocated stock is still physically present,
                             * therefore it is not deducted here.
                             */
                            $currentAvailable =
                                (float) (
                                    $balance['current_available']
                                    ?? $balance['available']
                                    ?? 0
                                );


                            /*
                             * Reserved but not yet released.
                             */
                            $allocated =
                                (float) ($balance['allocated'] ?? 0);


                            /*
                             * Physically released and still outstanding.
                             */
                            $borrowed =
                                (float) ($balance['borrowed'] ?? 0);


                            /*
                             * Other unavailable operational states.
                             */
                            $laundry =
                                (float) ($balance['laundry'] ?? 0);

                            $incident =
                                (float) ($balance['incident'] ?? 0);

                            $total =
                                (float) ($balance['total'] ?? 0);


                            /*
                             * Main operational status reflects actual
                             * physical inventory rather than a future
                             * reservation window.
                             */
                            $displayStatus =
                                !$item->borrowable
                                || $item->condition_code !== 'SERVICEABLE'

                                    ? (
                                        $item->condition_code === 'SERVICEABLE'
                                            ? 'UNAVAILABLE'
                                            : $item->condition_code
                                    )

                                    : (
                                        $currentAvailable > 0
                                            ? 'AVAILABLE'

                                            : (
                                                $borrowed > 0
                                                    ? 'BORROWED'

                                                    : (
                                                        $laundry > 0
                                                            ? 'LAUNDRY'

                                                            : (
                                                                $allocated > 0
                                                                    ? 'ALLOCATED'
                                                                    : 'UNAVAILABLE'
                                                            )
                                                    )
                                            )
                                    );
                        @endphp


                        <tr>


                            {{-- ================================= --}}
                            {{-- ITEM                              --}}
                            {{-- ================================= --}}

                            <td data-label="Item">

                                <strong>
                                    {{ $item->unique_description }}
                                </strong>


                                @if($item->specification)

                                    <small>
                                        {{ $item->specification }}
                                    </small>

                                @endif


                                <small>
                                    {{ $item->category->category_name }}
                                    &middot;
                                    {{ $item->unit->unit_name }}
                                </small>

                            </td>


                            {{-- ================================= --}}
                            {{-- PHYSICAL AVAILABILITY             --}}
                            {{-- ================================= --}}

                            <td data-label="Availability">

                                <strong
                                    class="
                                        operational-quantity
                                        {{
                                            $currentAvailable > 0
                                                ? 'is-available'
                                                : 'is-zero'
                                        }}
                                    "
                                >
                                    {{ $currentAvailable + 0 }}
                                </strong>


                                <small>
                                    of {{ $total + 0 }} total
                                </small>


                                <x-status-badge
                                    :status="$displayStatus"
                                />

                            </td>


                            {{-- ================================= --}}
                            {{-- COMMITTED QUANTITIES              --}}
                            {{-- ================================= --}}

                            <td data-label="Committed quantities">

                                <span class="quantity-pair">


                                    {{-- Always visible --}}
                                    <span>
                                        Allocated

                                        <strong>
                                            {{ $allocated + 0 }}
                                        </strong>
                                    </span>


                                    {{-- Always visible --}}
                                    <span>
                                        On custody

                                        <strong>
                                            {{ $borrowed + 0 }}
                                        </strong>
                                    </span>


                                    {{-- Only show when applicable --}}
                                    @if($laundry > 0)

                                        <span>
                                            In laundry

                                            <strong>
                                                {{ $laundry + 0 }}
                                            </strong>
                                        </span>

                                    @endif


                                    {{-- Only show when applicable --}}
                                    @if($incident > 0)

                                        <span>
                                            In accountability

                                            <strong>
                                                {{ $incident + 0 }}
                                            </strong>
                                        </span>

                                    @endif

                                </span>

                            </td>


                            {{-- ================================= --}}
                            {{-- CONDITION                         --}}
                            {{-- ================================= --}}

                            <td data-label="Condition">

                                <x-status-badge
                                    :status="$item->condition_code"
                                />


                                @if($incident > 0)

                                    <small>
                                        {{ $incident + 0 }}
                                        unit(s) in accountability
                                    </small>

                                @endif

                            </td>


                            {{-- ================================= --}}
                            {{-- USE CONDITIONS                    --}}
                            {{-- ================================= --}}

                            <td data-label="Use conditions">

                                <span>
                                    {{
                                        $item->off_campus_allowed
                                            ? 'Off-campus eligible'
                                            : 'On-campus only'
                                    }}
                                </span>


                                <small>
                                    {{
                                        $item->borrowable
                                            ? 'Borrowable'
                                            : 'Not borrowable'
                                    }}
                                </small>


                                @if($item->laundry_required)

                                    <small>
                                        Laundry Form required
                                    </small>

                                @endif


                                @if($item->provisional)

                                    <small class="text-warning">
                                        Quantity being confirmed
                                    </small>

                                @endif

                            </td>


                            {{-- ================================= --}}
                            {{-- ACTION                            --}}
                            {{-- ================================= --}}

                            <td data-label="Action">

                                @if($isSpmu)

                                    <a
                                        class="
                                            table-action
                                            ui-pressable
                                        "
                                        href="{{ route('inventory.edit', $item) }}"
                                    >

                                        <x-icon
                                            name="edit"
                                            size="16"
                                        />

                                        Edit item

                                    </a>

                                @else

                                    <span class="meta">
                                        View only
                                    </span>

                                @endif

                            </td>

                        </tr>


                    @empty

                        <tr>

                            <td
                                colspan="6"
                                class="empty-state"
                            >

                                <strong>
                                    No inventory items.
                                </strong>

                                <span>
                                    No active inventory record is available.
                                </span>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    @endif

</section>

@endsection