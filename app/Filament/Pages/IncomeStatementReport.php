<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use UnitEnum;

class IncomeStatementReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Income Statement';

    protected static ?int $navigationSort = 10;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::OVERVIEW;

    protected ?string $heading = 'Income Statement';

    protected function reportKey(): string
    {
        return 'income-statement';
    }

    protected function exportKey(): ?string
    {
        return 'income-statement';
    }

    protected function summaryStats(array $data): array
    {
        $revenue = $data['revenue'] ?? [];

        return [
            ['label' => 'Total Revenue', 'value' => Format::money($revenue['total'] ?? 0), 'description' => 'House cuts + gift/emoji sales', 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
            ['label' => 'Expenses', 'value' => Format::money($data['expenses']['total'] ?? 0), 'description' => ($data['expenses']['tracked'] ?? false) ? 'Recorded expenses' : 'Expenses not tracked', 'icon' => 'heroicon-m-receipt-percent', 'color' => 'warning'],
            ['label' => 'Referral Payouts', 'value' => Format::money(static::amountOf($data['referral_payouts'] ?? 0)), 'description' => 'Referral withdrawals completed; bonuses earned '.Format::money(static::amountOf($data['memo']['referral_bonuses_earned'] ?? 0)), 'icon' => 'heroicon-m-gift', 'color' => 'warning'],
            ['label' => 'Promotions', 'value' => Format::money(static::amountOf($data['promotions'] ?? 0)), 'description' => 'Signup bonuses granted, gross incl. excise', 'icon' => 'heroicon-m-ticket', 'color' => 'warning'],
            ['label' => 'Net Income', 'value' => Format::money($data['net_income'] ?? 0), 'description' => 'Revenue less expenses, referral payouts and promotions', 'icon' => 'heroicon-m-chart-bar', 'color' => ($data['net_income'] ?? 0) >= 0 ? 'primary' : 'danger'],
            ['label' => 'Unattributed Competition Income', 'value' => Format::money($revenue['competitions_unattributed'] ?? 0), 'description' => 'Not yet tied to a round tier', 'icon' => 'heroicon-m-question-mark-circle', 'color' => 'gray'],
        ];
    }

    protected function blocks(array $data): array
    {
        $revenue = $data['revenue'] ?? [];
        $money = fn ($value): string => Format::money($value);

        $rounds = fn (array $tier): array => collect($tier['by_rounds'] ?? [])
            ->map(fn ($amount, $rounds): array => [$rounds.' rounds', $money($amount)])
            ->values()
            ->all();

        return [
            [
                'title' => 'Revenue by stream',
                'headers' => ['Stream', 'Amount'],
                'rows' => [
                    ['Single games', $money($revenue['games'] ?? 0)],
                    ['Tournaments', $money($revenue['tournaments']['total'] ?? 0)],
                    ['Jackpots', $money($revenue['jackpots']['total'] ?? 0)],
                    ['Competitions (unattributed)', $money($revenue['competitions_unattributed'] ?? 0)],
                    ['Gift & emoji sales', $money($revenue['gift_emoji_sales']['total'] ?? 0)],
                    ['Other', $money($revenue['other'] ?? 0)],
                    ['Total', $money($revenue['total'] ?? 0)],
                ],
            ],
            [
                'title' => 'Single games by source',
                'headers' => ['Source', 'Amount'],
                'rows' => collect($revenue['games_by_source'] ?? [])
                    ->map(fn ($amount, $source): array => [str($source)->replace('_', ' ')->title()->toString(), $money($amount)])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Tournaments by rounds',
                'headers' => ['Rounds', 'Amount'],
                'rows' => $rounds($revenue['tournaments'] ?? []),
            ],
            [
                'title' => 'Jackpots by rounds',
                'headers' => ['Rounds', 'Amount'],
                'rows' => $rounds($revenue['jackpots'] ?? []),
            ],
            [
                'title' => 'Expenses by category',
                'headers' => ['Category', 'Amount'],
                'rows' => collect($data['expenses']['by_category'] ?? [])
                    ->map(fn ($row, $key): array => [
                        str(is_array($row) ? ($row['category'] ?? $key) : $key)->replace('_', ' ')->title()->toString(),
                        $money(is_array($row) ? ($row['amount'] ?? 0) : $row),
                    ])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Referral programme',
                'description' => 'Bonuses cost nothing until they are withdrawn, so only payouts are an expense.',
                'headers' => ['Line', 'Amount'],
                'rows' => [
                    ['Referral payouts (expense)', $money(static::amountOf($data['referral_payouts'] ?? 0))],
                    ['Bonuses earned (memo, not an expense)', $money(static::amountOf($data['memo']['referral_bonuses_earned'] ?? 0))],
                ],
            ],
            [
                'title' => 'Promotions',
                'description' => 'What the house wallet paid for signup bonuses, including the excise duty owed on them.',
                'headers' => ['Line', 'Amount'],
                'rows' => [
                    ['Signup bonuses (expense)', $money(static::amountOf($data['promotions'] ?? 0))],
                ],
            ],
            [
                'title' => 'Trend',
                'description' => 'Revenue and net income per '.($data['meta']['period']['group_by'] ?? 'day'),
                'headers' => ['Period', 'Games', 'Tournaments', 'Jackpots', 'Total revenue', 'Expenses', 'Referral payouts', 'Promotions', 'Net income'],
                'rows' => collect($data['series'] ?? [])
                    ->map(fn (array $row): array => [
                        $row['period'] ?? '—',
                        $money($row['games'] ?? 0),
                        $money($row['tournaments'] ?? 0),
                        $money($row['jackpots'] ?? 0),
                        $money($row['total'] ?? 0),
                        $money($row['expenses'] ?? 0),
                        $money(static::amountOf($row['referral_payouts'] ?? 0)),
                        $money(static::amountOf($row['promotions'] ?? 0)),
                        $money($row['net_income'] ?? 0),
                    ])
                    ->all(),
            ],
        ];
    }
}
