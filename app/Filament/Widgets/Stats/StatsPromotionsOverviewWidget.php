<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use App\Support\StatsFormat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * `/stats/promotions` `totals`: what promotions cost, how many players they reached, and the cost
 * per active player.
 */
class StatsPromotionsOverviewWidget extends StatsOverviewWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = ['default' => 2, 'xl' => 4];

    protected function statsReportKey(): string
    {
        return 'promotions';
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $totals = (array) ($this->stats()['totals'] ?? []);

        if ($this->statsProblem !== null) {
            return [Stat::make('Statistics unavailable', '—')->description($this->statsProblem)->color('warning')];
        }

        return [
            Stat::make('Promotions cost', StatsFormat::money($totals['cost'] ?? null))->description('Paid by the house in the period'),
            Stat::make('Cost per active player', StatsFormat::money($totals['cost_per_active_player'] ?? null))->description('Cost divided by active players'),
            Stat::make('Players reached', StatsFormat::count($totals['players_reached'] ?? null))->description('Received at least one costed promotion'),
            Stat::make('Active players', StatsFormat::count($totals['active_players'] ?? null))->description(StatsFormat::tooltip('active_players')),
        ];
    }
}
