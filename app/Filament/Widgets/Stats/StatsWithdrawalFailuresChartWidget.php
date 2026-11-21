<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * `/stats/payments` `withdrawals.failed.by_reason`. KadiApi groups B2C failures from the failure text,
 * since M-Pesa result codes are not stored.
 */
class StatsWithdrawalFailuresChartWidget extends ChartWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '260px';

    protected ?string $heading = 'Failed withdrawals by reason';

    protected function statsReportKey(): string
    {
        return 'payments';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function reasons(): array
    {
        return array_filter((array) ($this->stats()['withdrawals']['failed']['by_reason'] ?? []), fn ($figures): bool => is_array($figures) && (int) ($figures['count'] ?? 0) > 0);
    }

    public function getDescription(): string|Htmlable|null
    {
        $this->stats();

        return $this->statsProblem ?? ($this->reasons() === [] ? 'No failed withdrawals in the period.' : 'Grouped from the M-Pesa failure text.');
    }

    protected function getData(): array
    {
        $reasons = $this->reasons();
        $palette = ['rgba(239,68,68,0.75)', 'rgba(245,158,11,0.75)', 'rgba(59,130,246,0.75)', 'rgba(139,92,246,0.75)', 'rgba(107,114,128,0.75)'];

        return [
            'datasets' => [[
                'label' => 'Failed',
                'data' => array_values(array_map(fn (array $figures): int => (int) $figures['count'], $reasons)),
                'backgroundColor' => array_slice(array_merge($palette, $palette), 0, max(count($reasons), 1)),
            ]],
            'labels' => array_map(fn (string $reason): string => Str::headline($reason), array_keys($reasons)),
        ];
    }

    protected function getType(): string
    {
        return 'pie';
    }
}
