<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsDashboardStats;
use Filament\Widgets\Widget;

/**
 * `/stats/dashboard` `liability`: what is open now (wagers, stakes, potential payout), per product.
 */
class StatsLiabilityWidget extends Widget
{
    use ReadsDashboardStats;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.stats.liability';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['liability' => (array) ($this->dashboard()['liability'] ?? []), 'problem' => $this->statsProblem];
    }
}
