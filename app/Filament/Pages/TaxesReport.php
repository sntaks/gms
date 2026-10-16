<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use UnitEnum;

class TaxesReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Taxes (Estimate)';

    protected static ?int $navigationSort = 19;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::TAX;

    protected ?string $heading = 'Estimated Taxes';

    protected function reportKey(): string
    {
        return 'taxes';
    }

    protected function exportKey(): ?string
    {
        return 'taxes';
    }

    protected function summaryStats(array $data): array
    {
        $totals = $data['totals'] ?? [];

        return [
            ['label' => 'Net Income Before Tax', 'value' => Format::money($data['net_income_before_tax'] ?? 0), 'icon' => 'heroicon-m-chart-bar', 'color' => 'primary'],
            ['label' => 'Tax Expense', 'value' => Format::money($totals['expense_taxes'] ?? 0), 'description' => 'Excise and income tax', 'icon' => 'heroicon-m-receipt-percent', 'color' => 'warning'],
            ['label' => 'Pass-through Taxes', 'value' => Format::money($totals['pass_through_taxes'] ?? 0), 'description' => 'Withheld on winnings', 'icon' => 'heroicon-m-arrows-right-left', 'color' => 'info'],
            ['label' => 'Net Income After Tax', 'value' => Format::money($totals['net_income_after_tax'] ?? 0), 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
        ];
    }

    protected function blocks(array $data): array
    {
        $configured = (bool) ($data['configured'] ?? false);

        return [
            [
                'title' => 'Tax lines',
                'description' => $configured ? 'Estimated from configured rates.' : 'No tax rates are configured on the API yet, so every estimate is zero.',
                'headers' => ['Tax', 'Kind', 'Base', 'Base amount', 'Rate', 'Estimate'],
                'rows' => collect($data['taxes'] ?? [])
                    ->map(fn (array $tax): array => [
                        (string) ($tax['label'] ?? '—'),
                        str((string) ($tax['kind'] ?? ''))->replace('_', ' ')->title()->toString(),
                        str((string) ($tax['base'] ?? ''))->replace('_', ' ')->title()->toString(),
                        Format::money($tax['base_amount'] ?? 0),
                        rtrim(rtrim(number_format((float) ($tax['rate'] ?? 0) * 100, 2), '0'), '.').'%',
                        Format::money($tax['estimated_amount'] ?? 0),
                    ])
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
