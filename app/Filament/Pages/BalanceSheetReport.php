<?php

namespace App\Filament\Pages;

use App\Support\Format;
use BackedEnum;

class BalanceSheetReport extends FinanceReportPage
{
    /**
     * Liabilities whose key does not read well as a title.
     *
     * @var array<string, string>
     */
    public const LIABILITY_LABELS = [
        'bet_shop_floats' => 'Bet shop floats',
        'shop_withdrawals' => 'Shop withdrawals waiting to be collected',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Balance Sheet';

    protected static ?int $navigationSort = 12;

    protected ?string $heading = 'Balance Sheet';

    protected function reportKey(): string
    {
        return 'balance-sheet';
    }

    protected function usesPeriod(): bool
    {
        return false;
    }

    protected function summaryStats(array $data): array
    {
        $difference = (float) ($data['difference'] ?? 0);

        return [
            ['label' => 'Cash Held', 'value' => Format::money($data['assets']['total'] ?? 0), 'description' => 'M-Pesa accounts', 'icon' => 'heroicon-m-building-library', 'color' => 'success'],
            ['label' => 'Owed to Customers & Games', 'value' => Format::money($data['liabilities']['total'] ?? 0), 'description' => 'Wallets, escrow, referral wallets, bet shop floats, unmatched deposits', 'icon' => 'heroicon-m-user-group', 'color' => 'warning'],
            ['label' => 'House Wallet', 'value' => Format::money($data['house_wallet'] ?? 0), 'description' => 'Booked house income', 'icon' => 'heroicon-m-home', 'color' => 'info'],
            ['label' => 'Difference', 'value' => Format::money($difference), 'description' => 'Cash − owed − house wallet', 'icon' => 'heroicon-m-scale', 'color' => $difference >= 0 ? 'primary' : 'danger'],
        ];
    }

    protected function blocks(array $data): array
    {
        $money = fn ($value): string => Format::money($value);

        return [
            [
                'title' => 'Cash accounts',
                'description' => 'As of '.Format::date($data['as_of'] ?? null).' ('.($data['source'] ?? 'live').')',
                'headers' => ['Type', 'Account', 'Balance', 'Last fetched'],
                'rows' => collect($data['assets']['cash']['accounts'] ?? [])
                    ->map(fn (array $account): array => [
                        strtoupper((string) ($account['type'] ?? '')),
                        (string) ($account['account'] ?? '—'),
                        $money($account['amount'] ?? 0),
                        Format::dateTime($account['as_of'] ?? null),
                    ])
                    ->all(),
            ],
            [
                'title' => 'What is owed',
                'description' => 'Bet shop floats are owed back to owners if redeemed; shop withdrawals are cash players have yet to collect at a counter. A snapshot from before bet shops existed shows 0 for both.',
                'headers' => ['Liability', 'Amount'],
                'rows' => collect($data['liabilities'] ?? [])
                    ->map(fn ($amount, $key): array => [self::LIABILITY_LABELS[$key] ?? str($key)->replace('_', ' ')->title()->toString(), $money(static::amountOf($amount))])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Notes',
                'headers' => ['Note'],
                'rows' => collect($data['notes'] ?? [])->map(fn ($note): array => [(string) $note])->all(),
            ],
        ];
    }
}
