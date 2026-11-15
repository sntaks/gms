<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use UnitEnum;

/**
 * The referral programme's money (`GET /finance/referrals`): bonuses credited
 * to referral wallets, payouts made from the referral shortcode, and what is
 * still owed to referrers.
 */
class ReferralSummaryReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Referral Summary';

    /**
     * Beside Referral Bonuses (same sort; pages are discovered alphabetically).
     */
    protected static ?int $navigationSort = 27;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::PROMOTIONS;

    protected ?string $heading = 'Referral Programme';

    protected function reportKey(): string
    {
        return 'referrals';
    }

    protected function summaryStats(array $data): array
    {
        $bonuses = $data['bonuses_earned'] ?? [];
        $payouts = $data['payouts'] ?? [];
        $position = $data['position'] ?? [];

        return [
            ['label' => 'Bonuses Earned', 'value' => Format::money($bonuses['total'] ?? 0), 'description' => number_format((int) ($bonuses['count'] ?? 0)).' bonuses: sign-up '.Format::money($bonuses['signup'] ?? 0).', first deposit '.Format::money($bonuses['first_deposit'] ?? 0), 'icon' => 'heroicon-m-gift', 'color' => 'info'],
            ['label' => 'Paid Out', 'value' => Format::money($payouts['paid'] ?? 0), 'description' => 'Pending '.Format::money($payouts['pending'] ?? 0).', failed '.Format::money($payouts['failed'] ?? 0), 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
            ['label' => 'Owed to Referrers', 'value' => Format::money($position['unspent_balances'] ?? 0), 'description' => number_format((int) ($position['open_withdrawals']['count'] ?? 0)).' open withdrawals, '.Format::money($position['open_withdrawals']['amount'] ?? 0), 'icon' => 'heroicon-m-scale', 'color' => 'warning'],
            ['label' => 'Since Launch', 'value' => Format::money($position['lifetime_bonuses'] ?? 0), 'description' => 'Paid out '.Format::money($position['lifetime_paid_out'] ?? 0), 'icon' => 'heroicon-m-chart-bar', 'color' => 'gray'],
        ];
    }

    public function getWarnings(): array
    {
        return array_values(array_filter([...parent::getWarnings(), ...array_filter((array) ($this->getReport()['notes'] ?? []), 'is_string')]));
    }
}
