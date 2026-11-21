<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * `/stats/promotions` `series`: promotions cost per day, week or month.
 */
class StatsPromotionCostChartWidget extends ChartWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected ?string $heading = 'Promotions cost over time';

    protected function statsReportKey(): string
    {
        return 'promotions';
    }

    public function getDescription(): string|Htmlable|null
    {
        $this->stats();

        return $this->statsProblem ?? 'KES paid by the house in promotions.';
    }

    protected function getData(): array
    {
        $series = array_values(array_filter((array) ($this->stats()['series'] ?? []), 'is_array'));

        return [
            'datasets' => [[
                'label' => 'Cost',
                'data' => array_map(fn (array $point): float => (float) ($point['cost'] ?? 0), $series),
                'backgroundColor' => 'rgba(239,68,68,0.6)',
            ]],
            'labels' => array_map(fn (array $point): string => (string) ($point['date'] ?? ''), $series),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
