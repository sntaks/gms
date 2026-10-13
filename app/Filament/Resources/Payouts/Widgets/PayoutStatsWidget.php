<?php

namespace App\Filament\Resources\Payouts\Widgets;

use App\Enums\PayoutStatus;
use App\Models\MpesaAccountBalance;
use App\Models\Payout;
use App\Services\MpesaService;
use App\Services\PayoutDailyCap;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class PayoutStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $pendingPayouts = Payout::where('status', PayoutStatus::Pending)->get();
        $pendingCount = $pendingPayouts->count();
        $pendingAmount = $pendingPayouts->sum('amount');

        $approvedCount = Payout::whereIn('status', [PayoutStatus::Approved, PayoutStatus::Processing])->count();

        $thisMonthCompleted = Payout::where('status', PayoutStatus::Completed)
            ->whereMonth('processed_at', now()->month)
            ->whereYear('processed_at', now()->year)
            ->sum('amount');

        $failedCount = Payout::where('status', PayoutStatus::Failed)->count();

        $b2c = MpesaAccountBalance::latestOfType('b2c')->first();
        $stale = now()->subHours(2);

        $dailyCap = app(PayoutDailyCap::class);
        $capUsed = $dailyCap->usedToday();
        $capLimit = $dailyCap->cap();

        $b2cBalance = Cache::remember('mpesa_b2c_balance', 300, function () {
            try {
                return app(MpesaService::class)->getB2CBalance();
            } catch (\Throwable) {
                return 0;
            }
        });

        return [
            Stat::make('Daily Cap Usage', 'KES '.number_format($capUsed).' / '.number_format($capLimit))
                ->description($capUsed > $capLimit
                    ? 'Over cap by KES '.number_format($capUsed - $capLimit, 2).' — new payouts need a checker'
                    : 'KES '.number_format($capLimit - $capUsed, 2).' left today before a checker is required')
                ->descriptionIcon('heroicon-m-scale')
                ->color($this->getCapColor($capUsed, $capLimit)),
            Stat::make('Pending Payouts', $pendingCount)
                ->description('KES '.number_format($pendingAmount, 2).' pending')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Approved / Queued', $approvedCount)
                ->description('Waiting for M-Pesa processing')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('info'),
            Stat::make('Paid This Month', 'KES '.number_format($thisMonthCompleted, 2))
                ->description('Completed payouts')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Failed', $failedCount)
                ->description('Requires attention')
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color('danger'),
            Stat::make('B2C Utility Account', 'KES '.number_format($b2c?->utility_account_balance ?? 0, 2))
                ->description($this->formatLastUpdated($b2c?->fetched_at))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($this->getBalanceColor($b2c, $stale)),
        ];
    }

    private function getCapColor(float $used, float $cap): string
    {
        if ($cap <= 0 || $used >= $cap) {
            return 'danger';
        }

        return $used / $cap >= 0.75 ? 'warning' : 'success';
    }

    private function formatLastUpdated(?CarbonInterface $date): string
    {
        if (! $date) {
            return 'No data yet';
        }

        return 'Updated '.$date->diffForHumans();
    }

    private function getBalanceColor(?MpesaAccountBalance $balance, CarbonInterface $stale): string
    {
        if (! $balance || $balance->fetched_at->isBefore($stale)) {
            return 'warning';
        }

        return 'success';
    }
}
