@extends('layouts.app', ['title' => 'Available Items'])

@section('content')

@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $isSpmu = session('active_workspace') === 'SPMU';
@endphp


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
                Monitor current physical stock, scheduled allocations,
                active custody, and availability for a selected borrowing period.
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
    {{-- DATE AVAILABILITY FILTER                              --}}
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
                Availability for
                {{ $from->format('d M Y, g:i A') }}
                to
                {{ $to->format('d M Y, g:i A') }}
            </strong>

            <span>
                Quantities are calculated for the complete selected
                borrowing period and are rechecked when your request
                is submitted and finally approved.
            </span>

        </div>


        <div class="table-wrap borrower-inventory-table">

            <table>

                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Category and unit</th>
                        <th scope="col">Available quantity</th>
                        <th scope="col">Use conditions</th>
                        <th scope="col">Condition</th>
                    </tr>
                </thead>

                <tbody>

                    @forelse($items as $item)

                        @php
                            $balance = $balances[$item->id];
                        @endphp

                        <tr>

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


                            <td data-label="Category and unit">

                                {{ $item->category->category_name }}

                                <small>
                                    {{ $item->unit->unit_name }}
                                </small>

                            </td>


                            <td data-label="Available quantity">

                                @if(
                                    $item->borrowable
                                    && $item->condition_code === 'SERVICEABLE'
                                )

                                    <strong
                                        class="availability-number"
                                    >
                                        {{ $balance['available'] + 0 }}
                                    </strong>

                                    <small>
                                        of
                                        {{ $balance['total'] + 0 }}
                                        total
                                    </small>

                                    @if((float) $balance['available'] > 0)

                                        <x-status-badge
                                            status="AVAILABLE"
                                            label="Available for selected period"
                                        />

                                    @else

                                        <x-status-badge
                                            status="UNAVAILABLE"
                                            label="Unavailable for selected period"
                                        />

                                    @endif

                                @else

                                    <x-status-badge
                                        status="UNAVAILABLE"
                                        label="Not available for borrowing"
                                    />

                                @endif

                            </td>


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


                            <td data-label="Condition">

                                <x-status-badge
                                    :status="$item->condition_code"
                                />

                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td
                                colspan="5"
                                class="empty-state"
                            >

                                <strong>
                                    No inventory items are available to display.
                                </strong>

                                <span>
                                    Try again later or contact SPMU if
                                    you need assistance.
                                </span>

                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>


    {{-- ===================================================== --}}
    {{-- SPMU / OPERATIONAL VIEW                               --}}
    {{-- ===================================================== --}}

    @else

        <div
            class="availability-window"
            role="note"
        >

            <strong>
                Selected borrowing period:
                <x-date :value="$from" with-time />
                to
                <x-date :value="$to" with-time />
            </strong>

            <span>
                Physical available shows stock currently in SPMU custody.
                Selected-period availability also considers reservations
                and active borrowings that overlap the dates above.
            </span>

        </div>


        <div class="table-wrap operational-table inventory-operations-table">

            <table>

                <thead>
                    <tr>
                        <th scope="col">
                            Item
                        </th>

                        <th scope="col">
                            Physical availability
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

                            $currentAvailable =
                                (float) (
                                    $balance['current_available']
                                    ?? $balance['available']
                                );

                            $periodAvailable =
                                (float) $balance['available'];

                            $allocated =
                                (float) $balance['allocated'];

                            $borrowed =
                                (float) $balance['borrowed'];

                            $laundry =
                                (float) $balance['laundry'];

                            $incident =
                                (float) $balance['incident'];


                            /*
                             * Main operational status reflects the
                             * CURRENT physical inventory state.
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
                            {{-- CURRENT PHYSICAL AVAILABILITY     --}}
                            {{-- ================================= --}}

                            <td data-label="Physical availability">

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
                                    of {{ $balance['total'] + 0 }} total
                                </small>

                                <x-status-badge
                                    :status="$displayStatus"
                                />

                                <small>
                                    Selected period:
                                    <strong>
                                        {{ $periodAvailable + 0 }}
                                    </strong>
                                    available
                                </small>

                            </td>


                            {{-- ================================= --}}
                            {{-- COMMITMENTS                       --}}
                            {{-- ================================= --}}

                            <td data-label="Committed quantities">

                                <span class="quantity-pair">

                                    <span>
                                        Allocated
                                        <strong>
                                            {{ $allocated + 0 }}
                                        </strong>
                                    </span>

                                    <span>
                                        On custody
                                        <strong>
                                            {{ $borrowed + 0 }}
                                        </strong>
                                    </span>

                                    @if($laundry > 0)

                                        <span>
                                            In laundry
                                            <strong>
                                                {{ $laundry + 0 }}
                                            </strong>
                                        </span>

                                    @endif

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
                                        class="table-action ui-pressable"
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