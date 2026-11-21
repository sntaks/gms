<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsDashboardStats;
use Filament\Widgets\Widget;

/**
 * `/stats/dashboard` `players`: active players over the last day, week and month, new against
 * returning players in the period, and how many games each active player used.
 */
class StatsPlayersWidget extends Widget
{
    use ReadsDashboardStats;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.stats.players';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['players' => (array) ($this->dashboard()['players'] ?? []), 'problem' => $this->statsProblem];
    }
}
