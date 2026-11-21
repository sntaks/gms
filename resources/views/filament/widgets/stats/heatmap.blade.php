@php
    use App\Filament\Widgets\Stats\StatsHeatmapWidget;
    use App\Support\StatsFormat;

    $muted = 'font-size: 0.75rem; color: var(--gray-500);';
    $format = fn (float $value): string => $this->metric === 'stakes' ? StatsFormat::money($value) : number_format($value, 2);
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="When people play" description="Weekday and hour (Nairobi time) over the period.">
        <x-slot name="afterHeader">
            <div style="display: flex; gap: 0.5rem;">
                @foreach (StatsHeatmapWidget::METRICS as $key => $label)
                    <x-filament::button size="xs" :color="$this->metric === $key ? 'primary' : 'gray'" wire:click="showMetric('{{ $key }}')">{{ $label }}</x-filament::button>
                @endforeach
            </div>
        </x-slot>

        @if ($problem)
            <p style="{{ $muted }}">{{ $problem }}</p>
        @else
            <div style="overflow-x: auto;">
                <table style="border-collapse: separate; border-spacing: 2px; font-size: 0.6875rem; min-width: 46rem; width: 100%;">
                    <thead>
                        <tr>
                            <th></th>
                            @for ($hour = 0; $hour < 24; $hour++)
                                <th style="{{ $muted }} font-weight: 500; text-align: center;">{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (StatsHeatmapWidget::WEEKDAYS as $weekday => $label)
                            <tr>
                                <th style="{{ $muted }} font-weight: 500; text-align: left; padding-right: 0.5rem;">{{ $label }}</th>
                                @for ($hour = 0; $hour < 24; $hour++)
                                    @php($value = $cells["{$weekday}:{$hour}"] ?? 0.0)
                                    @php($share = $max > 0 ? $value / $max : 0)
                                    <td title="{{ $label }} {{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}:00 · {{ $format($value) }}"
                                        style="height: 1.5rem; border-radius: 0.25rem; background: {{ $value > 0 ? 'color-mix(in oklab, var(--primary-500) '.round(15 + $share * 85).'%, transparent)' : 'color-mix(in oklab, var(--gray-400) 12%, transparent)' }};"></td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p style="margin-top: 0.5rem; {{ $muted }}">A stronger colour means more. Highest cell: {{ $format($max) }}. Hover a cell for its value.</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
