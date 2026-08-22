@extends('layouts.app', ['title' => 'Inventory'])

@section('content')

@php
    $isBorrower = $workspace === 'BORROWER';
    $isSpmu = $workspace === 'SPMU';
@endphp

<section class="page-heading inventory-page-heading">
    <div>
        <p class="eyebrow">
            {{ $isBorrower ? 'Available items' : 'Inventory monitoring' }}
        </p>

        <h1>{{ $isBorrower ? 'Available Items' : 'Inventory' }}</h1>

        @if(!$isBorrower)
            <p>
                Monitor current stock, reservations, issued quantities, unavailable stock, and condition records.
            </p>
        @endif
    </div>

    @if($isSpmu)
        <a class="button primary ui-pressable" href="{{ route('inventory.create') }}">
            <x-icon name="plus" />
            Add inventory item
        </a>
    @endif
</section>

<section class="content-area inventory-page">

    <form id="inventory-search-form" method="get" class="filter-bar inventory-search-filter" aria-label="Filter inventory">
        <label class="inventory-search-field">
            Search item
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search item name or description..."
                autocomplete="off"
            >
        </label>

        <label>
            Category
            <select id="inventory-category-filter" name="category" onchange="this.form.submit()">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected($categoryId === $category->id)>
                        {{ $category->category_name }}
                    </option>
                @endforeach
            </select>
        </label>

        @if($search !== '' || $categoryId > 0)
            <a class="button secondary" href="{{ route('inventory.index') }}">Clear</a>
        @endif
    </form>

    @if(!$isBorrower)
        <form method="get" class="filter-bar availability-filter inventory-period-filter" aria-label="Check availability for a borrowing period">
            @if($search !== '')
                <input type="hidden" name="q" value="{{ $search }}">
            @endif
            @if($categoryId > 0)
                <input type="hidden" name="category" value="{{ $categoryId }}">
            @endif

            <label>
                Items needed from
                <input type="date" name="from" value="{{ $from->format('Y-m-d') }}">
            </label>

            <label>
                Expected return date
                <input type="date" name="to" value="{{ $to->format('Y-m-d') }}">
            </label>

            <button class="button secondary" type="submit">Check selected period</button>
        </form>
    @endif

    @if($isBorrower)

        <div class="table-wrap borrower-inventory-table inventory-reference-table">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Category</th>
                        <th scope="col">Available Quantity</th>
                        <th scope="col"><span class="visually-hidden">Action</span></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($items as $item)
                        @php
                            $balance = $balances[$item->id] ?? [];
                            $available = (float) (
                                $balance['borrower_available']
                                ?? $balance['available']
                                ?? 0
                            );
                        @endphp

                        <tr>
                            <td data-label="Item">
                                <strong>{{ $item->unique_description }}</strong>

                                @if($item->specification)
                                    <small>{{ $item->specification }}</small>
                                @endif
                            </td>

                            <td data-label="Category">
                                {{ $item->category->category_name }}
                                <small>{{ $item->unit->unit_name }}</small>
                            </td>

                            <td data-label="Available Quantity">
                                <span class="borrower-available-quantity">
                                    {{ $available + 0 }}
                                    <small>{{ $item->unit->unit_name }}</small>
                                </span>
                            </td>

                            <td data-label="Action">
                                <a
                                    class="table-action ui-pressable"
                                    href="{{ route('inventory.show', $item) }}"
                                >
                                    <x-icon name="eye" size="16" />
                                    View details
                                </a>
                            </td>
                        </tr>

                    @empty
                        <tr>
                            <td colspan="4" class="empty-state">
                                <strong>No available items found.</strong>
                                <span>
                                    Try another search or category.
                                </span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    @else
        <div class="availability-window" role="note">
            <strong>SPMU inventory breakdown</strong>
            <span>
                Available is stock still open for an approved allocation. Reserved is approved but not yet issued. Issued is property currently under borrower custody. Unavailable is the remaining quantity not currently suitable for allocation.
            </span>
            <span>
                Selected-period availability: {{ $from->format('d M Y, g:i A') }} to {{ $to->format('d M Y, g:i A') }}.
            </span>
        </div>

        <div class="table-wrap operational-table inventory-operations-table inventory-breakdown-table">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Total</th>
                        <th scope="col">Available</th>
                        <th scope="col">Reserved</th>
                        <th scope="col">Issued</th>
                        <th scope="col">Unavailable</th>
                        <th scope="col">Selected Period</th>
                        <th scope="col">Condition</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        @php
                            $balance = $balances[$item->id];
                            $total = (float) ($balance['total'] ?? 0);
                            $available = (float) ($balance['borrower_available'] ?? 0);
                            $reserved = (float) ($balance['reserved'] ?? 0);
                            $issued = (float) ($balance['borrowed'] ?? 0);
                            $periodAvailable = (float) ($balance['available'] ?? 0);
                            $unavailable = max(0, $total - $available - $reserved - $issued);
                        @endphp

                        <tr>
                            <td data-label="Item">
                                <strong>{{ $item->unique_description }}</strong>
                                @if($item->specification)
                                    <small>{{ $item->specification }}</small>
                                @endif
                                <small>{{ $item->category->category_name }} &middot; {{ $item->unit->unit_name }}</small>
                            </td>

                            <td data-label="Total"><strong>{{ $total + 0 }}</strong></td>
                            <td data-label="Available"><strong class="inventory-quantity-good">{{ $available + 0 }}</strong></td>
                            <td data-label="Reserved"><strong>{{ $reserved + 0 }}</strong></td>
                            <td data-label="Issued"><strong>{{ $issued + 0 }}</strong></td>
                            <td data-label="Unavailable"><strong>{{ $unavailable + 0 }}</strong></td>
                            <td data-label="Selected Period">
                                <strong>{{ $periodAvailable + 0 }}</strong>
                                <small>available for dates</small>
                            </td>
                            <td data-label="Condition">
                                <x-status-badge :status="$item->condition_code" />
                            </td>
                            <td data-label="Actions">
                                @if($isSpmu)
                                    <div class="inventory-row-actions">
                                        <a class="table-action ui-pressable inventory-view-details" href="{{ route('inventory.show', $item) }}">
                                            <x-icon name="eye" size="16" />
                                            View details
                                        </a>
                                        <a class="table-action ui-pressable" href="{{ route('inventory.edit', $item) }}">
                                            <x-icon name="edit" size="16" />
                                            Edit item
                                        </a>
                                    </div>
                                @else
                                    <span class="meta">View only</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="empty-state">
                                <strong>No matching inventory items.</strong>
                                <span>Try another search, category, or selected period.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

</section>


<style id="inventory-ui-batch-fix">

/* =========================================================
   AVAILABLE ITEMS — FINAL SMALL UI FIXES
   ========================================================= */


/* Eye icon + View details */

.inventory-view-details {
    display: inline-flex !important;
    align-items: center !important;

    gap: 7px !important;
}

.inventory-view-details svg {
    flex: 0 0 auto;
}


/* ---------------------------------------------------------
   CATEGORY SELECT

   Replace browser-native arrow so its position can be
   controlled precisely. Move it slightly inward.
   --------------------------------------------------------- */

.inventory-search-filter
select#inventory-category-filter {

    -webkit-appearance: none !important;
    appearance: none !important;

    padding-right: 34px !important;

    background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 20 20'%3E%3Cpath d='M5.5 7.5L10 12l4.5-4.5' fill='none' stroke='%23142235' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;

    background-repeat: no-repeat !important;

    /*
     * Slightly farther from the right edge.
     */
    background-position:
        right 10px center !important;

    background-size:
        12px 12px !important;
}


/* Keep dropdown readable */

.inventory-search-filter
select#inventory-category-filter:focus {

    background-position:
        right 10px center !important;
}

</style>


<script id="inventory-auto-category-filter">
document.addEventListener('DOMContentLoaded', function () {

    const form =
        document.getElementById('inventory-search-form');

    const category =
        document.getElementById('inventory-category-filter');

    if (!form || !category) {
        return;
    }

    category.addEventListener('change', function () {

        /*
         * Automatically apply the selected category.
         * The search input is in the same GET form,
         * therefore its current value is preserved.
         */
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        form.submit();
    });

});
</script>



<style id="inventory-final-small-fixes">

/* ==========================================================
   INVENTORY SMALL UI FIXES
   ========================================================== */


/* ----------------------------------------------------------
   VIEW DETAILS
   Give the eye icon breathing room from the text.
   ---------------------------------------------------------- */

.inventory-page .table-action {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
}

.inventory-page .table-action svg {
    flex: 0 0 auto !important;
}


/* ----------------------------------------------------------
   CATEGORY DROPDOWN CHEVRON
   Use a controlled arrow and move it slightly inward.
   ---------------------------------------------------------- */

#inventory-category-filter {
    -webkit-appearance: none !important;
    appearance: none !important;

    padding-right: 38px !important;

    background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 20 20'%3E%3Cpath d='M5.5 7.5L10 12l4.5-4.5' fill='none' stroke='%23142235' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;

    background-repeat: no-repeat !important;

    /*
     * Arrow is now around 12px from the right side,
     * instead of sitting against the border.
     */
    background-position:
        right 12px center !important;

    background-size:
        12px 12px !important;
}

</style>



<style id="inventory-final-docker-ui-fix">

/* Eye icon spacing */
.inventory-view-details {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
}

.inventory-view-details svg {
    flex: 0 0 auto !important;
}


/* Category dropdown arrow */
#inventory-category-filter {
    -webkit-appearance: none !important;
    appearance: none !important;

    padding-right: 38px !important;

    background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 20 20'%3E%3Cpath d='M5.5 7.5L10 12l4.5-4.5' fill='none' stroke='%23142235' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;

    background-repeat: no-repeat !important;

    /* Slightly inward from right edge */
    background-position: right 12px center !important;

    background-size: 12px 12px !important;
}

</style>

@endsection

