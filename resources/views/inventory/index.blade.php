@extends('layouts.app', ['title' => 'Inventory'])
@section('content')
<section class="page-heading"><div><p class="eyebrow">Inventory</p><h1>Available items</h1><p>Choose dates below to see how many items are available for the complete borrowing period.</p></div>@if(session('active_workspace') === 'SPMU')<a class="button primary" href="{{ route('inventory.create') }}">Add inventory item</a>@endif</section>
<section class="content-area">
    <form method="get" class="filter-bar"><label>Borrowing starts<input type="datetime-local" name="from" value="{{ $from->format('Y-m-d\TH:i') }}"></label><label>Return deadline<input type="datetime-local" name="to" value="{{ $to->format('Y-m-d\TH:i') }}"></label><button class="button primary">Check availability</button></form>
    <div class="table-wrap"><table><thead><tr><th>Item</th><th>Category</th><th>Unit</th><th>Total</th><th>Allocated</th><th>Borrowed</th><th>Available</th><th>Controls</th></tr></thead><tbody>
        @foreach($items as $item)<tr><td><strong>{{ $item->unique_description }}</strong><small>{{ $item->specification }}</small>@if($item->provisional)<span class="badge warning">Quantity being confirmed</span>@endif @if($item->laundry_required)<span class="badge">Laundry form required</span>@endif @if($item->off_campus_allowed)<span class="badge">May leave campus</span>@endif</td><td>{{ $item->category->category_name }}</td><td>{{ $item->unit->unit_name }}</td><td>{{ $balances[$item->id]['total'] }}</td><td>{{ $balances[$item->id]['allocated'] }}</td><td>{{ $balances[$item->id]['borrowed'] }}</td><td><strong>{{ $balances[$item->id]['available'] }}</strong></td><td>@if(session('active_workspace') === 'SPMU')<a class="table-action" href="{{ route('inventory.edit', $item) }}">Edit item</a>@else<span class="meta">View only</span>@endif</td></tr>@endforeach
    </tbody></table></div>
</section>
@endsection
