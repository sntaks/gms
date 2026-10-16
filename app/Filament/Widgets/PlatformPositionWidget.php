<?php

namespace App\Filament\Widgets;

use App\Concerns\LoadsFinanceReport;
use App\Filament\Pages\BalanceSheetReport;
use App\Filament\Pages\DepositsPage;
use App\Filament\Pages\PlayerWithdrawalsPage;
use App\Filament\Pages\ReconciliationReport;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Admin-only risk view: cash against what is owed, money that needs attention
 * (unmatched deposits, stuck escrow, stuck payouts) and the reconciliation result.
 */
class PlatformPositionWidget extends BaseWidget
{
    use LoadsFinanceReport;

    protected static ?int $sort = 20;

    protected ?string $pollingInterval = '120s';

    protected ?string $heading = 'Platform Position & Attention Needed';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $position = $this->loadFinanceReport('summary')['position'] ?? [];
        $reconciliation = $this->loadFinanceReport('reconciliation');
        $stuckPayouts = $this->loadFinanceReport('withdrawals', ['per_page' => 1])['summary']['stuck_pending'] ?? [];
        $error = $this->financeApiError;

        $liabilities = $position['liabilities'] ?? [];
        $cashByType = collect($position['assets']['cash']['accounts'] ?? [])
            ->groupBy(fn (array $account): string => strtoupper((string) ($account['type'] ?? '?')))
            ->map(fn ($accounts): float => (float) $accounts->sum('amount'));

        $unmatched = (float) ($liabilities['unmatched_deposits'] ?? 0);
        $stuckEscrow = (float) ($liabilities['stuck_escrow'] ?? 0);
        $stuckCount = (int) ($stuckPayouts['payments'] ?? 0);

        $reconStatus = (string) ($reconciliation['status'] ?? 'unknown');
        $counts = $reconciliation['counts'] ?? [];

        return [
            Stat::make('Cash Held (M-Pesa)', Format::compactMoney($position['assets']['total'] ?? 0))
                ->description($cashByType->map(fn (float $amount, string $type): string => "{$type} ".Format::compactMoney($amount))->implode(' · ') ?: 'No balances fetched yet')
                ->descriptionIcon('heroicon-m-building-library')
                ->color($error ? 'gray' : 'success')
                ->url(BalanceSheetReport::canAccess() ? BalanceSheetReport::getUrl() : null),

            Stat::make('Owed to Players', Format::compactMoney($liabilities['total'] ?? 0))
                ->description('Wallets '.Format::compactMoney($liabilities['customer_wallets'] ?? 0)
                    .' · Escrow '.Format::compactMoney(($liabilities['game_escrow'] ?? 0) + ($liabilities['competition_escrow'] ?? 0))
                    .' · Excise '.Format::compactMoney($liabilities['excise_duty_payable'] ?? 0)
                    .' · Disputes '.Format::compactMoney($liabilities['disputed_funds'] ?? 0)
                    .' · Shop floats '.Format::compactMoney($liabilities['bet_shop_floats'] ?? 0))
                ->descriptionIcon('heroicon-m-user-group')
                ->color($error ? 'gray' : 'info'),

            Stat::make('Unmatched Deposits', Format::compactMoney($unmatched))
                ->description('Money received but not credited to a player')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($error ? 'gray' : ($unmatched > 0 ? 'warning' : 'success'))
                ->url(DepositsPage::canAccess() ? DepositsPage::getUrl() : null),

            Stat::make('Stuck Escrow', Format::compactMoney($stuckEscrow))
                ->description('Held in games/competitions that never settled')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color($error ? 'gray' : ($stuckEscrow > 0 ? 'warning' : 'success')),

            Stat::make('Stuck Payouts', number_format($stuckCount))
                ->description($stuckCount > 0 ? Format::compactMoney($stuckPayouts['amount'] ?? 0).' pending over '.($stuckPayouts['threshold_hours'] ?? 24).'h' : 'No withdrawals stuck')
                ->descriptionIcon('heroicon-m-clock')
                ->color($error ? 'gray' : ($stuckCount > 0 ? 'danger' : 'success'))
                ->url(PlayerWithdrawalsPage::canAccess() ? PlayerWithdrawalsPage::getUrl() : null),

            Stat::make('Reconciliation', strtoupper($reconStatus))
                ->description(($counts['pass'] ?? 0).' pass · '.($counts['warn'] ?? 0).' warn · '.($counts['fail'] ?? 0).' fail')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($error ? 'gray' : match ($reconStatus) {
                    'pass' => 'success',
                    'warn' => 'warning',
                    'fail' => 'danger',
                    default => 'gray',
                })
                ->url(ReconciliationReport::canAccess() ? ReconciliationReport::getUrl() : null),
        ];
    }
}
