<?php

namespace App\Filament\Widgets\Stats;

/**
 * Daily active players as a line, with weekly and monthly active players as bars. A weekly or
 * monthly figure is drawn on the first day of the period that falls in its week or month.
 */
class StatsActivePlayersChartWidget extends StatsPlayersChartWidget
{
    protected ?string $heading = 'Active players: daily, weekly, monthly';

    protected function explanation(): ?string
    {
        return 'Line: players active each day. Bars: distinct players in each week and month.';
    }

    protected function getData(): array
    {
        $active = (array) ($this->stats()['active'] ?? []);
        $daily = array_values(array_filter((array) ($active['daily'] ?? []), 'is_array'));
        $labels = array_map(fn (array $point): string => (string) ($point['date'] ?? ''), $daily);

        return [
            'datasets' => [
                ['type' => 'line', 'label' => 'Daily (DAU)', 'data' => array_map(fn (array $point): int => (int) ($point['players'] ?? 0), $daily),
                    'borderColor' => 'rgb(139,92,246)', 'backgroundColor' => 'rgba(139,92,246,0.15)', 'tension' => 0.3, 'order' => 0],
                ['type' => 'bar', 'label' => 'Weekly (WAU)', 'data' => $this->onFirstDay($labels, (array) ($active['weekly'] ?? [])),
                    'backgroundColor' => 'rgba(59,130,246,0.45)', 'order' => 1],
                ['type' => 'bar', 'label' => 'Monthly (MAU)', 'data' => $this->onFirstDay($labels, (array) ($active['monthly'] ?? [])),
                    'backgroundColor' => 'rgba(16,185,129,0.35)', 'order' => 2],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * Place each bucket's figure on the first daily label that falls in it (buckets are sorted by start date).
     *
     * @param  list<string>  $labels
     * @param  array<int, mixed>  $buckets
     * @return list<?int>
     */
    protected function onFirstDay(array $labels, array $buckets): array
    {
        $starts = collect($buckets)->filter(fn ($bucket): bool => is_array($bucket) && filled($bucket['date'] ?? null))->values();
        $data = array_fill(0, count($labels), null);

        foreach ($starts as $index => $bucket) {
            $next = $starts[$index + 1]['date'] ?? null;

            foreach ($labels as $position => $label) {
                if ($label >= $bucket['date'] && ($next === null || $label < $next)) {
                    $data[$position] = (int) ($bucket['players'] ?? 0);

                    break;
                }
            }
        }

        return $data;
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
