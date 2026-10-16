<?php

namespace App\Filament\Widgets;

use App\Exceptions\GameApiException;
use App\Filament\Pages\BetShopsReport;
use App\Filament\Pages\FinanceReportPage;
use App\Filament\Pages\ShopLoadsPage;
use App\Filament\Pages\ShopWithdrawalsPage;
use App\Services\GameApiService;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Bet shop money for finance: the float the shops hold, cash loaded at counters
 * today, and cash withdrawals waiting to be collected. One cached
 * `/finance/bet-shops` call for today serves all three (float and pending
 * withdrawals are always as of now). Cached for a minute and never polled; when
 * KadiApi rate-limits the GMS key, nothing is called again until `Retry-After`.
 */
class BetShopsFinanceWidget extends BaseWidget
{
    public const CACHE_SECONDS = 60;

    /**
     * Holds the time KadiApi said to wait until after a 429.
     */
    public const BACKOFF_CACHE_KEY = 'bet-shops:finance:retry-at';

    protected static ?int $sort = 28;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Bet Shops';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return FinanceReportPage::canAccess();
    }

    /**
     * The bet shops finance report for a period, or why it is missing: `rate_limited` (with
     * the seconds left to wait) or `unavailable`.
     *
     * @return array{data: array<string, mixed>, error: ?string, retry_in: int}
     */
    public static function shopsReport(string $from, string $to): array
    {
        $retryAt = (int) Cache::get(self::BACKOFF_CACHE_KEY, 0);

        if ($retryAt > now()->timestamp) {
            return ['data' => [], 'error' => 'rate_limited', 'retry_in' => $retryAt - now()->timestamp];
        }

        try {
            $data = app(GameApiService::class)->financeReport('bet-shops', ['from' => $from, 'to' => $to, 'per_page' => 200], self::CACHE_SECONDS);

            return ['data' => $data, 'error' => null, 'retry_in' => 0];
        } catch (GameApiException $e) {
            if ($e->statusCode === 429) {
                $wait = max(1, $e->retryAfter ?? self::CACHE_SECONDS);
                Cache::put(self::BACKOFF_CACHE_KEY, now()->addSeconds($wait)->timestamp, $wait);

                return ['data' => [], 'error' => 'rate_limited', 'retry_in' => $wait];
            }

            Log::warning('Bet shops finance report failed', ['error' => $e->getMessage()]);

            return ['data' => [], 'error' => 'unavailable', 'retry_in' => 0];
        }
    }

    /**
     * What to show instead of a figure when the report is missing.
     *
     * @param  array{error: ?string, retry_in: int}  $report
     */
    public static function unavailableMessage(array $report): ?string
    {
        return match ($report['error']) {
            'rate_limited' => 'KadiApi rate limit reached; retrying in about '.$report['retry_in'].'s',
            'unavailable' => 'KadiApi could not be reached',
            default => null,
        };
    }

    protected function getStats(): array
    {
        $report = static::shopsReport(today()->toDateString(), today()->toDateString());
        $summary = $report['data']['summary'] ?? [];
        $problem = static::unavailableMessage($report);
        $pending = (int) ($summary['withdrawals_pending'] ?? 0);

        return [
            Stat::make('Float Held by Shops', Format::money($summary['float_balance'] ?? 0))
                ->description($problem ?? number_format((int) ($summary['shops'] ?? 0)).' shops · owed back to owners if redeemed')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color($problem !== null ? 'gray' : 'primary')
                ->url(BetShopsReport::getUrl()),

            Stat::make('Cash Loaded Today', Format::money($summary['loads_gross'] ?? 0))
                ->description($problem ?? number_format((int) ($summary['loads'] ?? 0)).' loads · excise '.Format::money($summary['loads_excise'] ?? 0))
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color($problem !== null ? 'gray' : 'success')
                ->url(ShopLoadsPage::canAccess() ? ShopLoadsPage::getUrl() : null),

            Stat::make('Pending Shop Withdrawals', number_format($pending))
                ->description($problem ?? Format::money($summary['withdrawals_pending_amount'] ?? 0).' waiting to be collected')
                ->descriptionIcon('heroicon-m-clock')
                ->color($problem !== null ? 'gray' : ($pending > 0 ? 'warning' : 'success'))
                ->url(ShopWithdrawalsPage::canAccess() ? ShopWithdrawalsPage::getUrlForStatus('pending') : null),
        ];
    }
}
