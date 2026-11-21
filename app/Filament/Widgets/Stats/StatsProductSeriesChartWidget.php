<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use App\Support\StatsFilters;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One product's `/stats/games` series: turnover and GGR in KES (left axis) and RTP in percent
 * (right axis). Kadi rake products have no RTP, so that line is left out for them.
 */
class StatsProductSeriesChartWidget extends ChartWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '260px';

    public string $product = '';

    protected function statsReportKey(): string
    {
        return 'games';
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(): array
    {
        return collect((array) ($this->stats()['by_product'] ?? []))
            ->first(fn ($row): bool => is_array($row) && ($row['product'] ?? null) === $this->product) ?? [];
    }

    public function getHeading(): string|Htmlable|null
    {
        return StatsFilters::productName($this->product);
    }

    public function getDescription(): string|Htmlable|null
    {
        $row = $this->row();

        return $this->statsProblem ?? StatsFilters::gameName($row['game'] ?? null).' · turnover and GGR in KES, RTP in %';
    }

    protected function getData(): array
    {
        $series = array_values(array_filter((array) ($this->row()['series'] ?? []), 'is_array'));
        $line = fn (string $key): array => array_map(fn (array $point): ?float => isset($point[$key]) ? (float) $point[$key] : null, $series);

        $datasets = [
            ['label' => 'Turnover', 'data' => $line('turnover'), 'borderColor' => 'rgb(245,158,11)', 'backgroundColor' => 'rgba(245,158,11,0.15)', 'yAxisID' => 'y', 'tension' => 0.3],
            ['label' => 'GGR', 'data' => $line('ggr'), 'borderColor' => 'rgb(16,185,129)', 'backgroundColor' => 'rgba(16,185,129,0.15)', 'yAxisID' => 'y', 'tension' => 0.3],
        ];

        if (collect($line('rtp'))->contains(fn ($value): bool => $value !== null)) {
            $datasets[] = ['label' => 'RTP %', 'data' => $line('rtp'), 'borderColor' => 'rgb(139,92,246)', 'borderDash' => [4, 3], 'yAxisID' => 'rtp', 'tension' => 0.3];
        }

        return ['datasets' => $datasets, 'labels' => array_map(fn (array $point): string => (string) ($point['date'] ?? ''), $series)];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
            {
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { ticks: { callback: (value) => Math.abs(value) >= 1000000 ? (value / 1000000).toFixed(2) + 'M' : Math.abs(value) >= 1000 ? (value / 1000).toFixed(2) + 'K' : value } },
                    rtp: { position: 'right', display: 'auto', grid: { drawOnChartArea: false }, ticks: { callback: (value) => value + '%' } },
                },
            }
            JS);
    }
}
