<?php

namespace App\Filament\Clusters\GameReports;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use UnitEnum;

/**
 * Game income and the leaderboards behind one sidebar entry, for anyone who can
 * view reports (agents included).
 */
class GameReportsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationLabel = 'Game Reports';

    protected static string|UnitEnum|null $navigationGroup = '📊 Finance';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'game-reports';

    protected static ?string $clusterBreadcrumb = 'Game Reports';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }
}
