@extends('layouts.app', ['title' => 'Available Items'])
@section('content')
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Inventory availability</p>
        <h1>{{ $isBorrower ? 'Available items' : 'Inventory' }}</h1>
    </div>
    @if($isBorrower)
        <a class="button primary ui-pressable" href="{{ route('requests.create') }}">Create borrowing request</a>
    @elseif(session('active_workspace') === 'SPMU')
        <a class="button primary ui-pressable" href="{{ route('inventory.create') }}"><x-icon name="plus" />Add inventory item</a>
    @endif
</section>

<section class="content-area">
    <form method="get" class="filter-bar availability-filter" aria-label="Check availability for a borrowing period">
        <label>Items needed from<input type="datetime-local" name="from" value="{{ $from->format('Y-m-d\TH:i') }}"></label>
        <label>Expected return date<input type="datetime-local" name="to" value="{{ $to->format('Y-m-d\TH:i') }}"></label>
        <button class="button primary">Check availability</button>
    </form>

    @if($isBorrower)
        <div class="availability-window" role="note">
            <strong>Availability for {{ $from->format('d M Y, g:i A') }} to {{ $to->format('d M Y, g:i A') }}</strong>
            <span>Quantities are calculated for the complete selected period and are rechecked when your request is submitted and approved.</span>
        </div>
        <div class="table-wrap borrower-inventory-table">
            <table>
                <thead><tr><th scope="col">Item</th><th scope="col">Category and unit</th><th scope="col">Available quantity</th><th scope="col">Use conditions</th><th scope="col">Condition</th></tr></thead>
                <tbody>
                @forelse($items as $item)
                    <tr>
                        <td data-label="Item"><strong>{{ $item->unique_description }}</strong>@if($item->specification)<small>{{ $item->specification }}</small>@endif</td>
                        <td data-label="Category and unit">{{ $item->category->category_name }}<small>{{ $item->unit->unit_name }}</small></td>
                        <td data-label="Available quantity">
                            @if($item->borrowable)
                                <strong class="availability-number">{{ $balances[$item->id]['available'] + 0 }}</strong>
                                <small>of {{ $balances[$item->id]['total'] + 0 }} total</small>
                            @else
                                <x-status-badge status="UNAVAILABLE" label="Not available for borrowing" />
                            @endif
                        </td>
                        <td data-label="Use conditions">
                            <span>{{ $item->off_campus_allowed ? 'Off-campus allowed' : 'On-campus only' }}</span>
                            @if($item->laundry_required)<small>Laundry Form required after use</small>@endif
                            @if($item->provisional)<small class="text-warning">Quantity is still being confirmed</small>@endif
                        </td>
                        <td data-label="Condition"><x-status-badge :status="$item->condition_code" /></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-state"><strong>No inventory items are available to display.</strong><span>Try again later or contact SPMU if you need assistance.</span></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="availability-window" role="note">
            <strong>Operational availability for <x-date :value="$from" with-time /> to <x-date :value="$to" with-time /></strong>
            <span>Available, allocated, and on-custody quantities use the application&rsquo;s existing date-aware inventory calculation.</span>
        </div>
        <div class="table-wrap operational-table inventory-operations-table"><table><thead><tr><th scope="col">Item</th><th scope="col">Availability</th><th scope="col">Committed quantities</th><th scope="col">Condition</th><th scope="col">Use conditions</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
            @forelse($items as $item)
                @php
                    $balance = $balances[$item->id];
                    $displayStatus = !$item->borrowable || $item->condition_code !== 'SERVICEABLE'
                        ? ($item->condition_code === 'SERVICEABLE' ? 'UNAVAILABLE' : $item->condition_code)
                        : ((float) $balance['available'] > 0 ? 'AVAILABLE' : ((float) $balance['allocated'] > 0 ? 'ALLOCATED' : ((float) $balance['borrowed'] > 0 ? 'BORROWED' : ((float) $balance['laundry'] > 0 ? 'LAUNDRY' : 'UNAVAILABLE'))));
                @endphp
                <tr>
                    <td data-label="Item"><strong>{{ $item->unique_description }}</strong>@if($item->specification)<small>{{ $item->specification }}</small>@endif<small>{{ $item->category->category_name }} &middot; {{ $item->unit->unit_name }}</small></td>
                    <td data-label="Availability"><strong class="operational-quantity {{ (float) $balance['available'] > 0 ? 'is-available' : 'is-zero' }}">{{ $balance['available'] + 0 }}</strong><small>of {{ $balance['total'] + 0 }} total</small><x-status-badge :status="$displayStatus" /></td>
                    <td data-label="Committed quantities"><span class="quantity-pair"><span>Allocated <strong>{{ $balance['allocated'] + 0 }}</strong></span><span>On custody <strong>{{ $balance['borrowed'] + 0 }}</strong></span>@if((float)$balance['laundry'] > 0)<span>In laundry <strong>{{ $balance['laundry'] + 0 }}</strong></span>@endif</span></td>
                    <td data-label="Condition"><x-status-badge :status="$item->condition_code" />@if((float)$balance['incident'] > 0)<small>{{ $balance['incident'] + 0 }} unit(s) in accountability</small>@endif</td>
                    <td data-label="Use conditions"><span>{{ $item->off_campus_allowed ? 'Off-campus eligible' : 'On-campus only' }}</span><small>{{ $item->borrowable ? 'Borrowable' : 'Not borrowable' }}</small>@if($item->laundry_required)<small>Laundry Form required</small>@endif @if($item->provisional)<small class="text-warning">Quantity being confirmed</small>@endif</td>
                    <td data-label="Action">@if(session('active_workspace') === 'SPMU')<a class="table-action ui-pressable" href="{{ route('inventory.edit', $item) }}"><x-icon name="edit" size="16" />Edit item</a>@else<span class="meta">View only</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-state"><strong>No inventory items.</strong><span>No active inventory record is available.</span></td></tr>
            @endforelse
        </tbody></table></div>
    @endif
</section>
@endsection
