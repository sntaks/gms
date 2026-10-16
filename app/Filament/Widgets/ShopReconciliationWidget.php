<?php

namespace App\Filament\Widgets;

use App\Concerns\LoadsFinanceReport;
use App\Filament\Pages\FinanceReportPage;
use App\Filament\Pages\ReconciliationReport;
use App\Filament\Pages\ShopWithdrawalsPage;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The bet shop controls from `GET /finance/reconciliation`, one card each with its
 * pass / warn / fail status. Stale shop withdrawals link to the pending withdrawals
 * list. Shares the cached reconciliation call with the other dashboard widgets;
 * never polled.
 */
class ShopReconciliationWidget extends BaseWidget
{
    use LoadsFinanceReport;

    /**
     * Checks shown when KadiApi reports them, in this order. Any other check whose key
     * mentions shops is shown after them.
     *
     * @var list<string>
     */
    public const CHECKS = [
        'stale_shop_withdrawals',
        'held_on_closed_shop_withdrawals',
        'bet_shop_float_drift',
        'shop_withdrawal_drift',
        'shop_float_ledger',
        'shop_loads_ledger',
    ];

    protected static ?int $sort = 30;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Bet Shop Reconciliation';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return FinanceReportPage::canAccess();
    }

    /**
     * @param  list<mixed>  $checks
     * @return list<array<string, mixed>>
     */
    public static function shopChecks(array $checks): array
    {
        $order = fn (array $check): int => ($position = array_search($check['key'], self::CHECKS, true)) === false ? PHP_INT_MAX : $position;

        return collect($checks)
            ->filter(fn ($check): bool => is_array($check) && str_contains((string) ($check['key'] ?? ''), 'shop'))
            ->sortBy($order)
            ->values()
            ->all();
    }

    protected function getStats(): array
    {
        $checks = static::shopChecks($this->loadFinanceReport('reconciliation', cacheSeconds: BetShopsFinanceWidget::CACHE_SECONDS)['checks'] ?? []);

        if ($checks === []) {
            return [
                Stat::make('Bet shop checks', $this->financeApiError ? 'Unavailable' : 'Not reported')
                    ->description($this->financeApiError ? 'KadiApi could not be reached' : 'KadiApi returned no bet shop checks')
                    ->color('gray'),
            ];
        }

        return array_map(fn (array $check): Stat => $this->checkStat($check), $checks);
    }

    /**
     * @param  array<string, mixed>  $check
     */
    protected function checkStat(array $check): Stat
    {
        $status = (string) ($check['status'] ?? 'unknown');
        $count = (int) ($check['count'] ?? 0);

        $url = match (true) {
            $check['key'] === 'stale_shop_withdrawals' && ShopWithdrawalsPage::canAccess() => ShopWithdrawalsPage::getUrlForStatus('pending'),
            ReconciliationReport::canAccess() => ReconciliationReport::getUrl(),
            default => null,
        };

        return Stat::make($check['title'] ?? ucfirst(str_replace('_', ' ', (string) $check['key'])), strtoupper($status))
            ->description($status === 'pass'
                ? 'Nothing to act on'
                : number_format($count).' item'.($count === 1 ? '' : 's').' · '.Format::money($check['amount'] ?? 0))
            ->descriptionIcon(match ($status) {
                'pass' => 'heroicon-m-check-circle',
                'warn' => 'heroicon-m-exclamation-triangle',
                'fail' => 'heroicon-m-x-circle',
                default => 'heroicon-m-question-mark-circle',
            })
            ->color(match ($status) {
                'pass' => 'success',
                'warn' => 'warning',
                'fail' => 'danger',
                default => 'gray',
            })
            ->extraAttributes(['title' => (string) ($check['detail'] ?? '')])
            ->url($url);
    }
}
