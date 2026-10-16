<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use UnitEnum;

class TrialBalanceReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Trial Balance';

    protected static ?int $navigationSort = 13;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::OVERVIEW;

    protected ?string $heading = 'Trial Balance';

    protected function reportKey(): string
    {
        return 'trial-balance';
    }

    protected function exportKey(): ?string
    {
        return 'trial-balance';
    }

    protected function summaryStats(array $data): array
    {
        $balanced = (bool) ($data['check']['balanced'] ?? false);

        return [
            ['label' => 'Total Debits', 'value' => Format::money($data['totals']['debit'] ?? 0), 'icon' => 'heroicon-m-arrow-trending-up', 'color' => 'info'],
            ['label' => 'Total Credits', 'value' => Format::money($data['totals']['credit'] ?? 0), 'icon' => 'heroicon-m-arrow-trending-down', 'color' => 'info'],
            ['label' => 'Ledger Check', 'value' => $balanced ? 'Balanced' : 'Out of balance', 'description' => 'Imbalance: '.Format::money($data['check']['imbalance'] ?? 0), 'icon' => $balanced ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle', 'color' => $balanced ? 'success' : 'danger'],
        ];
    }

    protected function blocks(array $data): array
    {
        $money = fn ($value): string => Format::money($value);

        return [
            [
                'title' => 'Ledger movement by account',
                'headers' => ['Account', 'Entry type', 'Category', 'Debit', 'Credit', 'Entries'],
                'rows' => collect($data['lines'] ?? [])
                    ->map(fn (array $line): array => [
                        (string) ($line['account'] ?? '—'),
                        (string) ($line['entry_type'] ?? '—'),
                        (string) ($line['category'] ?? '—'),
                        $money($line['debit'] ?? 0),
                        $money($line['credit'] ?? 0),
                        number_format((int) ($line['entries'] ?? 0)),
                    ])
                    ->all(),
            ],
            [
                'title' => 'Imbalance by category',
                'headers' => ['Category', 'Amount'],
                'rows' => collect($data['check']['imbalance_by_category'] ?? [])
                    ->map(fn ($amount, $category): array => [str($category)->replace('_', ' ')->title()->toString(), $money($amount)])
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
