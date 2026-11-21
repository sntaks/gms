<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Widgets\Stats\StatsActivePlayersChartWidget;
use App\Filament\Widgets\Stats\StatsChannelsWidget;
use App\Filament\Widgets\Stats\StatsCohortWidget;
use App\Filament\Widgets\Stats\StatsPlayersOverviewWidget;
use App\Filament\Widgets\Stats\StatsRegistrationsChartWidget;
use App\Filament\Widgets\Stats\StatsStakeBandsChartWidget;
use BackedEnum;

/**
 * `/stats/players`: registrations, first-deposit conversion, daily/weekly/monthly active players,
 * weekly retention cohorts, churn, stake bands and channels.
 */
class StatsPlayersPage extends StatsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Players';

    protected static ?string $title = 'Players';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'players';

    public static function statsReport(): string
    {
        return 'players';
    }

    public static function statsExport(): ?string
    {
        return null;
    }

    protected function statsWidgets(): array
    {
        return [
            StatsPlayersOverviewWidget::class,
            StatsRegistrationsChartWidget::class,
            StatsActivePlayersChartWidget::class,
            StatsCohortWidget::class,
            StatsStakeBandsChartWidget::class,
            StatsChannelsWidget::class,
        ];
    }
}
