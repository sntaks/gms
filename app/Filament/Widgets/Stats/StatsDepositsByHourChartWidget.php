<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * `/stats/payments` `deposits.by_hour`: deposits credited in each Nairobi hour over the period.
 */
class StatsDepositsByHourChartWidget extends ChartWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '260px';

    protected ?string $heading = 'Deposits by hour';

    protected function statsReportKey(): string
    {
        return 'payments';
    }

    public function getDescription(): string|Htmlable|null
    {
        $this->stats();

        return $this->statsProblem ?? 'Deposits credited in each hour of the day (Nairobi time), over the period.';
    }

    protected function getData(): array
    {
        $hours = collect((array) ($this->stats()['deposits']['by_hour'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && isset($row['hour']))
            ->keyBy(fn (array $row): int => (int) $row['hour']);

        return [
            'datasets' => [[
                'label' => 'Deposits',
                'data' => array_map(fn (int $hour): int => (int) ($hours[$hour]['count'] ?? 0), range(0, 23)),
                'backgroundColor' => 'rgba(16,185,129,0.7)',
            ]],
            'labels' => array_map(fn (int $hour): string => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00', range(0, 23)),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
