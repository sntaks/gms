<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsDashboardStats;
use App\Support\StatsFormat;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A line chart of `/stats/dashboard` `series`, with the compare window's `previous_series` dashed.
 * Subclasses name the figures to draw; a figure the API sends as null (cash figures under a game or
 * product filter) is left out.
 */
abstract class StatsSeriesChartWidget extends ChartWidget
{
    use ReadsDashboardStats;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '280px';

    /**
     * Figure key => [label, rgb colour, y axis].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    abstract protected function figures(): array;

    protected function getData(): array
    {
        $dashboard = $this->dashboard();
        $series = array_values((array) ($dashboard['series'] ?? []));
        $previous = array_values((array) ($dashboard['previous_series'] ?? []));
        $datasets = [];

        foreach ($this->figures() as $key => [$label, $rgb, $axis]) {
            $points = array_map(fn (array $point) => $point[$key] ?? null, $series);

            if (collect($points)->every(fn ($value): bool => $value === null)) {
                continue;
            }

            $datasets[] = [
                'label' => $label,
                'data' => array_map(fn ($value): ?float => $value === null ? null : (float) $value, $points),
                'borderColor' => "rgb({$rgb})",
                'backgroundColor' => "rgba({$rgb},0.15)",
                'yAxisID' => $axis,
                'tension' => 0.3,
            ];

            if ($previous !== []) {
                $datasets[] = [
                    'label' => $label.' (compared)',
                    'data' => array_map(fn (int $index): ?float => isset($previous[$index][$key]) ? (float) $previous[$index][$key] : null, array_keys($series)),
                    'borderColor' => "rgba({$rgb},0.55)",
                    'borderDash' => [6, 4],
                    'pointRadius' => 0,
                    'yAxisID' => $axis,
                    'tension' => 0.3,
                ];
            }
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(fn (array $point): string => (string) ($point['date'] ?? ''), $series),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): string|Htmlable|null
    {
        $this->dashboard();

        return $this->statsProblem ?? 'Dashed lines: the compared period. Amounts in KES ('.StatsFormat::TIMEZONE.' days).';
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
            {
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        ticks: {
                            callback: (value) => Math.abs(value) >= 1000000
                                ? (value / 1000000).toFixed(1) + 'M'
                                : Math.abs(value) >= 1000 ? (value / 1000).toFixed(0) + 'k' : value,
                        },
                    },
                    players: { position: 'right', display: 'auto', beginAtZero: true, grid: { drawOnChartArea: false } },
                },
            }
            JS);
    }
}
