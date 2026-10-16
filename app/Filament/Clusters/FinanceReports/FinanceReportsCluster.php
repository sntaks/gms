<?php

namespace App\Filament\Clusters\FinanceReports;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use UnitEnum;

/**
 * Every company-level finance report behind one sidebar entry. The reports are
 * listed in a grouped menu beside each report; groups follow the reports' sort
 * order. Finance only: the entry is hidden from anyone who can open none of them.
 */
class FinanceReportsCluster extends Cluster
{
    public const OVERVIEW = 'Overview';

    public const TAX = 'Tax';

    public const ACTIVITY = 'Activity';

    public const PROMOTIONS = 'Promotions';

    public const BET_SHOPS = 'Bet Shops';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Finance Reports';

    protected static string|UnitEnum|null $navigationGroup = '📊 Finance';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'finance-reports';

    protected static ?string $clusterBreadcrumb = 'Finance Reports';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }
}
