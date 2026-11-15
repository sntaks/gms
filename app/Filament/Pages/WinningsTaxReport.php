<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use UnitEnum;

/**
 * Withholding tax on winnings (`GET /finance/winnings-tax`): tax withheld and
 * reversed in the period, what was paid to KRA, and what is still payable.
 * The rate is per product and the base is the profit on a settlement.
 */
class WinningsTaxReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Winnings Tax';

    /**
     * Beside Excise Duty (same sort; pages are discovered alphabetically).
     */
    protected static ?int $navigationSort = 15;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::TAX;

    protected ?string $heading = 'Withholding Tax on Winnings';

    protected function reportKey(): string
    {
        return 'winnings-tax';
    }

    protected function summaryStats(array $data): array
    {
        $totals = $data['totals'] ?? [];
        $payable = $data['payable'] ?? [];

        return [
            ['label' => 'Tax Withheld', 'value' => Format::money($totals['tax_charged'] ?? 0), 'description' => number_format((int) ($totals['charges'] ?? 0)).' taxed settlements', 'icon' => 'heroicon-m-receipt-percent', 'color' => 'info'],
            ['label' => 'Reversed', 'value' => Format::money($totals['tax_reversed'] ?? 0), 'description' => 'Net '.Format::money($totals['tax_net'] ?? 0), 'icon' => 'heroicon-m-arrow-uturn-left', 'color' => 'gray'],
            ['label' => 'Paid to KRA', 'value' => Format::money($totals['tax_remitted'] ?? 0), 'description' => 'By payment date', 'icon' => 'heroicon-m-check-badge', 'color' => 'success'],
            ['label' => 'Still Payable', 'value' => Format::money($payable['outstanding'] ?? 0), 'description' => filled($payable['oldest_unremitted_at'] ?? null) ? 'Oldest from '.Format::date($payable['oldest_unremitted_at']) : 'Nothing outstanding', 'icon' => 'heroicon-m-building-library', 'color' => ((float) ($payable['outstanding'] ?? 0)) > 0 ? 'warning' : 'success'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [[
            'title' => 'By period',
            'headers' => ['Period', 'Settlements', 'Withheld', 'Reversed', 'Net', 'Paid to KRA'],
            'rows' => collect($data['series'] ?? [])->map(fn (array $row): array => [
                (string) ($row['period'] ?? ''),
                number_format((int) ($row['charges'] ?? 0)),
                Format::money($row['tax_charged'] ?? 0),
                Format::money($row['tax_reversed'] ?? 0),
                Format::money($row['tax_net'] ?? 0),
                Format::money($row['tax_remitted'] ?? 0),
            ])->all(),
        ]];
    }

    public function getWarnings(): array
    {
        return array_values(array_filter([...parent::getWarnings(), ...array_filter((array) ($this->getReport()['notes'] ?? []), 'is_string')]));
    }
}
