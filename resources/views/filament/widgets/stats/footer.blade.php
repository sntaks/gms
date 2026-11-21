@php
    use App\Support\Format;
    use App\Support\StatsFormat;

    $muted = 'font-size: 0.75rem; color: var(--gray-500);';
@endphp

<x-filament-widgets::widget>
    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
        @foreach ($warnings as $warning)
            <div role="alert" style="display: flex; gap: 0.5rem; align-items: flex-start; padding: 0.75rem 1rem; border-radius: 0.75rem; border: 1px solid var(--warning-300); background: color-mix(in oklab, var(--warning-400) 15%, transparent);">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--warning-600);" />
                <p style="font-size: 0.875rem; color: var(--warning-700);">{{ $warning }}</p>
            </div>
        @endforeach

        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; align-items: center; justify-content: space-between;">
            <p style="{{ $muted }}">
                @if ($problem)
                    {{ $problem }}
                @else
                    Figures as of {{ StatsFormat::time($generatedAt) }} (Nairobi time).
                    @if (filled($range['from'] ?? null))
                        Period {{ Format::date($range['from']) }} – {{ Format::date($range['to'] ?? null) }}@if (filled($compare['from'] ?? null)), compared with {{ Format::date($compare['from']) }} – {{ Format::date($compare['to'] ?? null) }}@endif.
                    @endif
                @endif
            </p>

        </div>
    </div>
</x-filament-widgets::widget>
