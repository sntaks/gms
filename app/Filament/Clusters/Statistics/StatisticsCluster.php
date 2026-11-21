<?php

namespace App\Filament\Clusters\Statistics;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use UnitEnum;

/**
 * KadiApi statistics for every game and product: games, liability, players, top players,
 * promotions and payments. Admins and finance see them all; trading roles see Liability.
 */
class StatisticsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Statistics';

    protected static string|UnitEnum|null $navigationGroup = '📊 Finance';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'statistics';

    protected static ?string $clusterBreadcrumb = 'Statistics';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }
}
