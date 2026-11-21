<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Widgets\Stats\StatsLiabilityProductsWidget;
use App\Filament\Widgets\Stats\StatsTopPositionsWidget;
use App\Models\User;
use BackedEnum;

/**
 * `/stats/liability`: open exposure now, per product, and the ten largest open positions. Open to
 * the trading roles as well as finance, since it is exposure rather than revenue.
 */
class StatsLiabilityPage extends StatsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Liability';

    protected static ?string $title = 'Liability now';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'liability';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && ($user->canViewFinanceReports()
            || $user->hasPermissionTo('sportsbook.view')
            || $user->hasPermissionTo('predict.view'));
    }

    public static function statsReport(): string
    {
        return 'liability';
    }

    public static function statsExport(): ?string
    {
        return null;
    }

    protected function statsWidgets(): array
    {
        return [StatsLiabilityProductsWidget::class, StatsTopPositionsWidget::class];
    }
}
