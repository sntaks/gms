<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use UnitEnum;

class CashFlowReport extends FinanceReportPage
{
    /**
     * Cash-in sources whose key does not read well as a title.
     *
     * @var array<string, string>
     */
    public const CASH_IN_LABELS = [
        'shop_float' => 'Shop float bought',
    ];

    public const SHOP_CASH_NOTE = 'Cash that changed hands at bet shop counters. It never reaches M-Pesa, so it is not in net cash.';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Cash Flow';

    protected static ?int $navigationSort = 11;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::OVERVIEW;

    protected ?string $heading = 'Cash Flow';

    protected function reportKey(): string
    {
        return 'cash-flow';
    }

    protected function exportKey(): ?string
    {
        return 'cash-flow';
    }

    protected function summaryStats(array $data): array
    {
        $totals = $data['totals'] ?? [];

        return [
            ['label' => 'Cash In', 'value' => Format::money($totals['cash_in']['total'] ?? 0), 'description' => 'M-Pesa payments received', 'icon' => 'heroicon-m-arrow-down-tray', 'color' => 'success'],
            ['label' => 'Cash Out (Paid)', 'value' => Format::money($totals['cash_out']['paid'] ?? 0), 'description' => 'Withdrawals paid to players', 'icon' => 'heroicon-m-arrow-up-tray', 'color' => 'info'],
            ['label' => 'Pending Payouts', 'value' => Format::money($totals['cash_out']['pending'] ?? 0), 'description' => 'Awaiting M-Pesa confirmation', 'icon' => 'heroicon-m-clock', 'color' => 'warning'],
            ['label' => 'Referral Payouts (Paid)', 'value' => Format::money(static::referralPayouts($data)['paid'] ?? 0), 'description' => 'From the 4151665 referral shortcode', 'icon' => 'heroicon-m-gift', 'color' => 'warning'],
            ['label' => 'Cash at Shop Counters', 'value' => Format::money($totals['shop_cash']['loaded'] ?? 0), 'description' => 'Loaded by players · paid out '.Format::money($totals['shop_cash']['paid_out'] ?? 0).' · not M-Pesa', 'icon' => 'heroicon-m-building-storefront', 'color' => 'gray'],
            ['label' => 'Net Cash', 'value' => Format::money($totals['net_cash'] ?? 0), 'description' => 'Cash in less paid withdrawals, excise remitted, referral payouts, refunds and float paid back to shops', 'icon' => 'heroicon-m-banknotes', 'color' => ($totals['net_cash'] ?? 0) >= 0 ? 'primary' : 'danger'],
        ];
    }

    /**
     * The `referral_payouts` block (paid, pending, failed), which sits with the other totals.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function referralPayouts(array $data): array
    {
        $payouts = $data['totals']['referral_payouts'] ?? $data['referral_payouts'] ?? [];

        return is_array($payouts) ? $payouts : [];
    }

    protected function blocks(array $data): array
    {
        $totals = $data['totals'] ?? [];
        $money = fn ($value): string => Format::money($value);
        $label = fn (string $key): string => str($key)->replace('_', ' ')->title()->toString();

        return [
            [
                'title' => 'Cash in by source',
                'headers' => ['Source', 'Amount'],
                'rows' => collect($totals['cash_in'] ?? [])
                    ->map(fn ($amount, $source): array => [self::CASH_IN_LABELS[$source] ?? $label($source), $money($amount)])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Cash out by status',
                'headers' => ['Status', 'Amount'],
                'rows' => collect($totals['cash_out'] ?? [])
                    ->map(fn ($amount, $status): array => [$label($status), $money($amount)])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Referral payouts (4151665)',
                'description' => 'Paid by completion date, failed by failure date, pending by request date.',
                'headers' => ['Status', 'Amount'],
                'rows' => collect(static::referralPayouts($data))
                    ->map(fn ($amount, $status): array => [$label($status), $money($amount)])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Shop float paid back',
                'description' => 'Float bought by bet shop owners and paid back to them by GMS. It reduces net cash.',
                'headers' => ['Item', 'Amount'],
                'rows' => [['Redeemed', $money($totals['shop_float']['redeemed'] ?? 0)]],
            ],
            [
                'title' => 'Cash at shop counters',
                'description' => self::SHOP_CASH_NOTE,
                'headers' => ['Item', 'Amount'],
                'rows' => [
                    ['Loaded by players (gross)', $money($totals['shop_cash']['loaded'] ?? 0)],
                    ['Paid out to players', $money($totals['shop_cash']['paid_out'] ?? 0)],
                ],
            ],
            [
                'title' => 'Trend',
                'description' => 'Per '.($data['meta']['period']['group_by'] ?? 'day'),
                'headers' => ['Period', 'Cash in', 'Paid out', 'Pending', 'Failed', 'Referral paid', 'Float redeemed', 'Shop cash in', 'Shop cash out', 'Net cash'],
                'rows' => collect($data['series'] ?? [])
                    ->map(fn (array $row): array => [
                        $row['period'] ?? '—',
                        $money($row['cash_in']['total'] ?? 0),
                        $money($row['cash_out']['paid'] ?? 0),
                        $money($row['cash_out']['pending'] ?? 0),
                        $money($row['cash_out']['failed'] ?? 0),
                        $money(static::referralPayouts($row)['paid'] ?? 0),
                        $money($row['shop_float']['redeemed'] ?? 0),
                        $money($row['shop_cash']['loaded'] ?? 0),
                        $money($row['shop_cash']['paid_out'] ?? 0),
                        $money($row['net_cash'] ?? 0),
                    ])
                    ->all(),
            ],
        ];
    }
}
