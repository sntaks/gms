<?php

namespace App\Filament\Widgets\Stats;

/**
 * GGR, net and turnover over the period, against the compared period.
 */
class StatsRevenueChartWidget extends StatsSeriesChartWidget
{
    protected ?string $heading = 'GGR, net and turnover';

    protected function figures(): array
    {
        return [
            'ggr' => ['GGR', '16,185,129', 'y'],
            'net' => ['Net', '59,130,246', 'y'],
            'turnover' => ['Turnover', '245,158,11', 'y'],
        ];
    }
}
