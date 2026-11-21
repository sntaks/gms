<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Widgets\Stats\StatsTopPlayersWidget;
use BackedEnum;

/**
 * `/stats/players/top`: the top 20, 50 or 100 players by turnover, GGR, deposits, net loss or wins.
 */
class StatsTopPlayersPage extends StatsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationLabel = 'Top players';

    protected static ?string $title = 'Top players';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'top-players';

    public static function statsReport(): string
    {
        return 'players/top';
    }

    public static function statsExport(): ?string
    {
        return 'stats-players-top';
    }

    protected function statsWidgets(): array
    {
        return [StatsTopPlayersWidget::class];
    }
}
