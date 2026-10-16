<?php

namespace App\Filament\Widgets;

use App\Enums\CompanyWithdrawStatus;
use App\Enums\WithdrawStatus;
use App\Models\CompanyWithdraw;
use App\Models\Withdraw;
use App\Support\Format;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class WithdrawalsThisMonthWidget extends BaseWidget
{
    protected static ?int $sort = 31;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Withdrawals This Month';

    public static function canView(): bool
    {
        return true;
    }

    protected function getStats(): array
    {
        $monthStart = now()->startOfMonth();

        $companyPaid = (float) CompanyWithdraw::query()
            ->where('status', CompanyWithdrawStatus::Completed->value)
            ->where('created_at', '>=', $monthStart)
            ->sum('amount');

        $companyPending = CompanyWithdraw::query()
            ->where('status', CompanyWithdrawStatus::Pending->value)
            ->count();

        $shareholderPaid = (float) Withdraw::query()
            ->where('status', WithdrawStatus::Completed->value)
            ->where('created_at', '>=', $monthStart)
            ->sum('amount');

        $shareholderPending = Withdraw::query()
            ->where('status', WithdrawStatus::Pending->value)
            ->count();

        $fmt = fn ($v) => $v !== null && $v !== '' && $v !== 0.0 ? Format::formatNumber($v) : '—';
        $fmtInt = fn ($v) => $v !== null && $v !== '' && $v !== 0 ? Format::formatNumber($v) : '—';

        return [
            Stat::make('Company Withdrawals', 'KES '.$fmt($companyPaid))
                ->description($fmtInt($companyPending).' pending approval')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('info')
                ->chart($this->dailyTotals(CompanyWithdraw::class, $monthStart)),

            Stat::make('Shareholder Withdrawals', 'KES '.$fmt($shareholderPaid))
                ->description($fmtInt($shareholderPending).' pending approval')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success')
                ->chart($this->dailyTotals(Withdraw::class, $monthStart)),

            Stat::make('Total Payouts', 'KES '.$fmt($companyPaid + $shareholderPaid))
                ->description('Company + shareholder, this month')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
        ];
    }

    /**
     * Withdrawal totals per day for the current month, used as a sparkline.
     *
     * @param  class-string<Model>  $model
     * @return array<int, float>
     */
    private function dailyTotals(string $model, CarbonInterface $monthStart): array
    {
        $byDay = $model::query()
            ->where('created_at', '>=', $monthStart)
            ->get(['created_at', 'amount'])
            ->groupBy(fn (Model $record): int => $record->created_at->day)
            ->map(fn ($group): float => (float) $group->sum('amount'));

        return collect(range(1, now()->day))
            ->map(fn (int $day): float => $byDay->get($day, 0.0))
            ->all();
    }
}
