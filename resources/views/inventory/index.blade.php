@extends('layouts.app', ['title' => 'Inventory'])

@section('content')

@php
    $isBorrower = $workspace === 'BORROWER';
    $isSpmu = $workspace === 'SPMU';
@endphp

<section class="page-heading inventory-page-heading">
    <div>
        <p class="eyebrow">
            {{ $isBorrower ? 'Borrowable inventory' : 'Inventory monitoring' }}
        </p>

        <h1>Inventory</h1>

        <p>
            @if($isBorrower)
                View current quantities that are eligible and suitable for borrowing.
            @else
                Monitor current stock, reservations, issued quantities, unavailable stock, and condition records.
            @endif
        </p>
    </div>

    @if($isBorrower)
        <a class="button primary ui-pressable" href="{{ route('requests.create') }}">
            <x-icon name="plus" />
            Create borrowing request
        </a>
    @elseif($isSpmu)
        <a class="button primary ui-pressable" href="{{ route('inventory.create') }}">
            <x-icon name="plus" />
            Add inventory item
        </a>
    @endif
</section>

<section class="content-area inventory-page">

    @if($isBorrower)
        <div class="inventory-reference-note" role="note">
            <x-icon name="information" size="18" />
            <div>
                <strong>Availability is for reference only.</strong>
                <p>
                    Submission of a borrowing request does not reserve an item. Final availability and approval are subject to SPMU verification. Only approved reservations reduce the quantity shown as available.
                </p>
            </div>
        </div>
    @endif

    <form method="get" class="filter-bar inventory-search-filter" aria-label="Filter inventory">
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
            <select name="category">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected($categoryId === $category->id)>
                        {{ $category->category_name }}
                    </option>
                @endforeach
            </select>
        </label>

        <button class="button primary" type="submit">Apply filters</button>

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
                <input type="datetime-local" name="from" value="{{ $from->format('Y-m-d\TH:i') }}">
            </label>

            <label>
                Expected return date
                <input type="datetime-local" name="to" value="{{ $to->format('Y-m-d\TH:i') }}">
            </label>

            <button class="button secondary" type="submit">Check selected period</button>
        </form>
    @endif

    @if($isBorrower)
        <div class="inventory-legend" aria-label="Availability status legend">
            <span><i class="inventory-dot is-available" aria-hidden="true"></i><strong>Available</strong> — full suitable quantity is currently available</span>
            <span><i class="inventory-dot is-limited" aria-hidden="true"></i><strong>Limited</strong> — only part of the suitable quantity is currently available</span>
            <span><i class="inventory-dot is-unavailable" aria-hidden="true"></i><strong>Unavailable</strong> — no suitable quantity is currently available</span>
        </div>

        <div class="table-wrap borrower-inventory-table inventory-reference-table">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Category</th>
                        <th scope="col">Available Quantity</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        @php
                            $balance = $balances[$item->id];
                            $available = (float) ($balance['borrower_available'] ?? 0);
                            $total = (float) ($balance['total'] ?? 0);

                            $availabilityStatus = $available <= 0
                                || $item->condition_code !== 'SERVICEABLE'
                                    ? 'UNAVAILABLE'
                                    : ($available < $total ? 'LIMITED' : 'AVAILABLE');
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

                            <td data-label="Status">
                                @if($availabilityStatus === 'AVAILABLE')
                                    <x-status-badge status="AVAILABLE" label="Available" />
                                @elseif($availabilityStatus === 'LIMITED')
                                    <x-status-badge status="LOW_STOCK" label="Limited" />
                                @else
                                    <x-status-badge status="UNAVAILABLE" label="Unavailable" />
                                @endif
                            </td>

                            <td data-label="Action">
                                <a class="table-action ui-pressable" href="{{ route('inventory.show', $item) }}">
                                    <x-icon name="eye" size="16" />
                                    View details
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="empty-state">
                                <strong>No matching inventory items.</strong>
                                <span>Try another search or category.</span>
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
                                        <a class="table-action ui-pressable" href="{{ route('inventory.show', $item) }}">
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

@endsection
