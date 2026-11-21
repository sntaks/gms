<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Widgets\Stats\StatsPromotionCostChartWidget;
use App\Filament\Widgets\Stats\StatsPromotionProductsWidget;
use App\Filament\Widgets\Stats\StatsPromotionsOverviewWidget;
use App\Filament\Widgets\Stats\StatsPromotionTypesWidget;
use BackedEnum;

/**
 * `/stats/promotions`: what promotions cost, by type and by product, and over time.
 */
class StatsPromotionsPage extends StatsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Promotions';

    protected static ?string $title = 'Promotions';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'promotions';

    public static function statsReport(): string
    {
        return 'promotions';
    }

    public static function statsExport(): ?string
    {
        return 'stats-promotions';
    }

    protected function statsWidgets(): array
    {
        return [
            StatsPromotionsOverviewWidget::class,
            StatsPromotionTypesWidget::class,
            StatsPromotionProductsWidget::class,
            StatsPromotionCostChartWidget::class,
        ];
    }
}
