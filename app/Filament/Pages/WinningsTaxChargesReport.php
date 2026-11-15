<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use UnitEnum;

/**
 * Winnings tax per row (`GET /finance/winnings-tax/charges`): a charge on a
 * taxed settlement, or the credit when that settlement was reversed.
 */
class WinningsTaxChargesReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = 'Winnings Tax Charges';

    protected static ?int $navigationSort = 17;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::TAX;

    protected ?string $heading = 'Winnings Tax Charges';

    protected function reportKey(): string
    {
        return 'winnings-tax/charges';
    }

    protected function extraFilters(): array
    {
        return ['kind' => $this->filterValue('kind'), 'status' => $this->filterValue('status')];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $byKind = $summary['by_kind'] ?? [];

        return [
            ['label' => 'Net Tax', 'value' => Format::money($summary['tax_net'] ?? 0), 'description' => number_format((int) ($summary['rows'] ?? 0)).' rows', 'icon' => 'heroicon-m-receipt-percent', 'color' => 'info'],
            ['label' => 'Charges', 'value' => Format::money($byKind['charge']['tax'] ?? 0), 'description' => 'On '.Format::money($byKind['charge']['tax_base'] ?? 0).' of winnings', 'icon' => 'heroicon-m-plus-circle', 'color' => 'primary'],
            ['label' => 'Reversal Credits', 'value' => Format::money($byKind['reversal']['tax'] ?? 0), 'description' => number_format((int) ($byKind['reversal']['rows'] ?? 0)).' reversals', 'icon' => 'heroicon-m-arrow-uturn-left', 'color' => 'gray'],
            ['label' => 'Not Yet Paid to KRA', 'value' => Format::money($summary['unremitted']['tax'] ?? 0), 'description' => number_format((int) ($summary['unremitted']['rows'] ?? 0)).' rows', 'icon' => 'heroicon-m-building-library', 'color' => 'warning'],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('charged_at')->label('When')->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('kind')->badge()
                ->color(fn (?string $state): string => $state === 'reversal' ? 'gray' : 'primary')
                ->formatStateUsing(fn (?string $state): string => $state === 'reversal' ? 'Reversal credit' : 'Charge'),
            TextColumn::make('customer_name')->label('Player')->placeholder('—')->description(fn (array $record): ?string => isset($record['customer_id']) ? '#'.$record['customer_id'] : null),
            TextColumn::make('product')->description(fn (array $record): ?string => isset($record['wager_reference']) ? $record['wager_reference'].' v'.($record['settlement_version'] ?? '?') : null),
            TextColumn::make('tax_base')->label('Winnings')->alignEnd()->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('rate')->alignEnd()->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format(((float) $state) * 100, 2), '0'), '.').'%'),
            TextColumn::make('amount')->label('Tax')->alignEnd()->weight('bold')->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('kra_reference')->label('Paid with')->placeholder('Not yet paid'),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('kind')->options(['charge' => 'Charge', 'reversal' => 'Reversal credit']),
            SelectFilter::make('status')->options(['unremitted' => 'Not yet paid to KRA', 'remitted' => 'Paid to KRA']),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No winnings tax in this period';
    }
}
