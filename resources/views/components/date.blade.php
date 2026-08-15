@props([
    'value' => null,
    'withTime' => false,
    'withSeconds' => false,
    'fallback' => 'Not recorded',
])

@if($value)
    <time datetime="{{ $value->toIso8601String() }}">{{ $value->format($withSeconds ? 'd M Y, g:i:s A' : ($withTime ? 'd M Y, g:i A' : 'd M Y')) }}</time>
@else
    {{ $fallback }}
@endif
