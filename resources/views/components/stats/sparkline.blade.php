@props(['values' => [], 'width' => 96, 'height' => 24])

{{-- A small inline trend line. It only draws the API's points; empty or flat series draw a flat line. --}}
@php
    $points = array_values(array_map(fn ($value): float => (float) $value, array_filter($values, 'is_numeric')));
    $count = count($points);
    $min = $count ? min($points) : 0;
    $max = $count ? max($points) : 0;
    $span = $max - $min ?: 1;
    $coordinates = collect($points)->map(fn (float $value, int $index): string => round($count > 1 ? $index * $width / ($count - 1) : $width / 2, 1).','.round($height - 2 - ($value - $min) / $span * ($height - 4), 1))->implode(' ');
    $rising = $count > 1 && end($points) >= $points[0];
@endphp

@if ($count > 1)
    <svg width="{{ $width }}" height="{{ $height }}" viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="Trend" {{ $attributes }}>
        <polyline points="{{ $coordinates }}" fill="none" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"
            style="stroke: var({{ $rising ? '--success-500' : '--danger-500' }});" />
    </svg>
@else
    <span style="color: var(--gray-400);">—</span>
@endif
