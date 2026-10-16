<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use UnitEnum;

/**
 * Excise duty charged on plain M-Pesa deposits, what was reversed and remitted
 * to KRA, and what is still owed.
 */
class ExciseDutyReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Excise Duty';

    protected static ?int $navigationSort = 15;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::TAX;

    protected ?string $heading = 'Excise Duty';

    protected function reportKey(): string
    {
        return 'excise-duty';
    }

    protected function exportKey(): ?string
    {
        return 'excise-duty';
    }

    protected function summaryStats(array $data): array
    {
        $totals = $data['totals'] ?? [];
        $payable = $data['payable'] ?? [];
        $outstanding = (float) ($payable['outstanding'] ?? 0);
        $rate = (float) ($data['rate'] ?? 0);

        return [
            ['label' => 'Payable to KRA', 'value' => Format::money($outstanding), 'description' => $outstanding > 0 && filled($payable['oldest_unremitted_at'] ?? null) ? 'Unremitted since '.Format::date($payable['oldest_unremitted_at']) : 'All duty remitted', 'icon' => 'heroicon-m-building-library', 'color' => $outstanding > 0 ? 'warning' : 'success'],
            ['label' => 'Excise Charged', 'value' => Format::money($totals['excise_charged'] ?? 0), 'description' => number_format((int) ($totals['deposits'] ?? 0)).' deposits · '.Format::money($totals['gross_deposits'] ?? 0).($rate > 0 ? ' at '.self::percent($rate) : ''), 'icon' => 'heroicon-m-receipt-percent', 'color' => 'info'],
            ['label' => 'Reversed', 'value' => Format::money($totals['excise_reversed'] ?? 0), 'description' => 'Given back on refunded deposits', 'icon' => 'heroicon-m-arrow-uturn-left', 'color' => 'gray'],
            ['label' => 'Remitted', 'value' => Format::money($totals['excise_remitted'] ?? 0), 'description' => 'Net '.Format::money($totals['excise_net'] ?? 0).' for the period', 'icon' => 'heroicon-m-check-badge', 'color' => 'success'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'By period',
                'description' => ($data['enabled'] ?? true) ? null : 'Excise duty is currently disabled on the API.',
                'headers' => ['Period', 'Deposits', 'Gross deposits', 'Charged', 'Reversed', 'Net', 'Remitted'],
                'rows' => collect($data['series'] ?? [])
                    ->filter(fn ($row): bool => is_array($row))
                    ->map(fn (array $row): array => [
                        (string) ($row['period'] ?? '—'),
                        number_format((int) ($row['deposits'] ?? 0)),
                        Format::money($row['gross_deposits'] ?? 0),
                        Format::money($row['excise_charged'] ?? 0),
                        Format::money($row['excise_reversed'] ?? 0),
                        Format::money($row['excise_net'] ?? 0),
                        Format::money($row['excise_remitted'] ?? 0),
                    ])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Notes',
                'headers' => ['Note'],
                'rows' => collect($data['notes'] ?? [])
                    ->filter(fn ($note): bool => is_string($note))
                    ->map(fn (string $note): array => [$note])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * A rate such as 0.05 as "5%".
     */
    public static function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2), '0'), '.').'%';
    }
}
