<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use App\Support\StatsFormat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * One group of `/stats/payments` figures: deposits and STK pushes, withdrawals, held withdrawals, or
 * bet shop cash. The page shows each group as its own row.
 */
class StatsPaymentsOverviewWidget extends StatsOverviewWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = ['default' => 2, 'xl' => 4];

    /**
     * @var array<string, string>
     */
    public const GROUPS = [
        'deposits' => 'Deposits and STK pushes',
        'withdrawals' => 'Withdrawals',
        'held' => 'Held withdrawals',
        'bet_shops' => 'Bet shop cash',
    ];

    public string $group = 'deposits';

    protected function statsReportKey(): string
    {
        return 'payments';
    }

    protected function getHeading(): ?string
    {
        return self::GROUPS[$this->group] ?? null;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $stats = $this->stats();

        if ($this->statsProblem !== null) {
            return [Stat::make('Statistics unavailable', '—')->description($this->statsProblem)->color('warning')];
        }

        return match ($this->group) {
            'withdrawals' => $this->withdrawals((array) ($stats['withdrawals'] ?? [])),
            'held' => $this->held((array) ($stats['held_withdrawals'] ?? [])),
            'bet_shops' => $this->betShops((array) ($stats['bet_shops'] ?? [])),
            default => $this->deposits((array) ($stats['deposits'] ?? []), (array) ($stats['stk_push'] ?? [])),
        };
    }

    /**
     * @param  array<string, mixed>  $deposits
     * @param  array<string, mixed>  $stk
     * @return list<Stat>
     */
    protected function deposits(array $deposits, array $stk): array
    {
        return [
            Stat::make('Deposits credited', StatsFormat::money($deposits['amount'] ?? null))
                ->description(StatsFormat::count($deposits['count'] ?? null).' deposits · average '.StatsFormat::money($deposits['average'] ?? null)),
            Stat::make('Unmatched deposits', StatsFormat::money($deposits['unmatched']['amount'] ?? null))
                ->description(StatsFormat::count($deposits['unmatched']['count'] ?? null).' not credited to a player'),
            Stat::make('STK push success', StatsFormat::percent($stk['success_rate'] ?? null))
                ->description(StatsFormat::count($stk['completed']['count'] ?? null).' of '.StatsFormat::count($stk['count'] ?? null).' pushes completed'),
            Stat::make('STK push time', StatsFormat::duration($stk['avg_seconds_to_complete'] ?? null))
                ->description('Average from push to payment'),
        ];
    }

    /**
     * @param  array<string, mixed>  $withdrawals
     * @return list<Stat>
     */
    protected function withdrawals(array $withdrawals): array
    {
        return [
            Stat::make('Paid', StatsFormat::money($withdrawals['paid']['amount'] ?? null))
                ->description(StatsFormat::count($withdrawals['paid']['count'] ?? null).' paid · average '.StatsFormat::money($withdrawals['paid']['average'] ?? null)),
            Stat::make('Failed', StatsFormat::count($withdrawals['failed']['count'] ?? null))
                ->description(StatsFormat::money($withdrawals['failed']['amount'] ?? null).' · '.StatsFormat::percent($withdrawals['failure_rate'] ?? null).' of settled')
                ->color('danger'),
            Stat::make('Time to pay', StatsFormat::duration($withdrawals['avg_seconds_to_complete'] ?? null))
                ->description('Average from request to result'),
            Stat::make('Pending now', StatsFormat::count($withdrawals['pending_now']['count'] ?? null))
                ->description(StatsFormat::money($withdrawals['pending_now']['amount'] ?? null).' not yet paid or failed')
                ->color('warning'),
        ];
    }

    /**
     * @param  array<string, mixed>  $held
     * @return list<Stat>
     */
    protected function held(array $held): array
    {
        $decided = collect((array) ($held['decided'] ?? []))
            ->filter(fn ($figures): bool => is_array($figures))
            ->map(fn (array $figures, string $status): string => StatsFormat::count($figures['count'] ?? 0).' '.$status)
            ->implode(' · ');

        return [
            Stat::make('Held for approval', StatsFormat::count($held['requested']['count'] ?? null))
                ->description(StatsFormat::money($held['requested']['amount'] ?? null).' · requests of '.StatsFormat::money($held['threshold'] ?? null).' or more'),
            Stat::make('Decided', StatsFormat::count(collect((array) ($held['decided'] ?? []))->sum(fn ($figures): int => (int) ($figures['count'] ?? 0))))
                ->description($decided !== '' ? $decided : 'None decided'),
            Stat::make('Average wait', StatsFormat::duration($held['avg_wait_seconds'] ?? null))
                ->description('From request to decision'),
            Stat::make('Awaiting now', StatsFormat::count($held['awaiting_now']['count'] ?? null))
                ->description(filled($held['awaiting_now']['oldest_since'] ?? null)
                    ? StatsFormat::money($held['awaiting_now']['amount'] ?? null).' · oldest since '.StatsFormat::time($held['awaiting_now']['oldest_since'])
                    : 'Nothing waiting')
                ->color('warning'),
        ];
    }

    /**
     * @param  array<string, mixed>  $shops
     * @return list<Stat>
     */
    protected function betShops(array $shops): array
    {
        return [
            Stat::make('Loads', StatsFormat::money($shops['loads']['amount'] ?? null))
                ->description(StatsFormat::count($shops['loads']['count'] ?? null).' loads'),
            Stat::make('Loads reversed', StatsFormat::money($shops['loads_reversed']['amount'] ?? null))
                ->description(StatsFormat::count($shops['loads_reversed']['count'] ?? null).' reversed'),
            Stat::make('Cash-outs', StatsFormat::money($shops['cash_outs']['amount'] ?? null))
                ->description(StatsFormat::count($shops['cash_outs']['count'] ?? null).' paid'),
            Stat::make('Cash-out time', StatsFormat::duration($shops['cash_outs']['avg_seconds_to_pay'] ?? null))
                ->description('Average from request to payment'),
        ];
    }
}
