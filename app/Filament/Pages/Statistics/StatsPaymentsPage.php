<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Widgets\Stats\StatsDepositsByHourChartWidget;
use App\Filament\Widgets\Stats\StatsPaymentsOverviewWidget;
use App\Filament\Widgets\Stats\StatsWithdrawalFailuresChartWidget;
use BackedEnum;

/**
 * `/stats/payments`: deposits, STK pushes, withdrawals, held withdrawals and bet shop cash. These are
 * platform-wide, so the page has no game or product filter.
 */
class StatsPaymentsPage extends StatsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $title = 'Payments';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'payments';

    public static function statsReport(): string
    {
        return 'payments';
    }

    public static function statsExport(): ?string
    {
        return 'stats-payments';
    }

    protected function statsWidgets(): array
    {
        return [
            StatsPaymentsOverviewWidget::make(['group' => 'deposits']),
            StatsDepositsByHourChartWidget::class,
            StatsWithdrawalFailuresChartWidget::class,
            StatsPaymentsOverviewWidget::make(['group' => 'withdrawals']),
            StatsPaymentsOverviewWidget::make(['group' => 'held']),
            StatsPaymentsOverviewWidget::make(['group' => 'bet_shops']),
        ];
    }
}
