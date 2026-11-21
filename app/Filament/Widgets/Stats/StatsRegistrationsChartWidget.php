<?php

namespace App\Filament\Widgets\Stats;

/**
 * Sign-ups per day, week or month of the period.
 */
class StatsRegistrationsChartWidget extends StatsPlayersChartWidget
{
    protected ?string $heading = 'Registrations';

    protected function explanation(): ?string
    {
        return 'Players who signed up, per day (week or month for longer periods).';
    }

    protected function getData(): array
    {
        $series = array_values(array_filter((array) ($this->stats()['registrations']['series'] ?? []), 'is_array'));

        return [
            'datasets' => [[
                'label' => 'Registrations',
                'data' => array_map(fn (array $point): int => (int) ($point['registrations'] ?? 0), $series),
                'backgroundColor' => 'rgba(245,158,11,0.7)',
            ]],
            'labels' => array_map(fn (array $point): string => (string) ($point['date'] ?? ''), $series),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
