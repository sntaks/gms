<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A chart of `/stats/players`; subclasses pick the figures.
 */
abstract class StatsPlayersChartWidget extends ChartWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '260px';

    protected function statsReportKey(): string
    {
        return 'players';
    }

    public function getDescription(): string|Htmlable|null
    {
        $this->stats();

        return $this->statsProblem ?? $this->explanation();
    }

    abstract protected function explanation(): ?string;
}
