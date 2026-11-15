<?php

namespace App\Filament\Pages;

use App\Support\Format;
use Filament\Tables\Columns\TextColumn;

/**
 * Monthly winnings tax returns: what was due to KRA for each month, what was
 * paid and what is still outstanding. Same shape as the excise returns.
 */
class WinningsTaxReturnsReport extends ExciseReturnsReport
{
    protected static ?string $navigationLabel = 'Winnings Tax Returns';

    protected ?string $heading = 'Winnings Tax Returns';

    protected function reportKey(): string
    {
        return 'winnings-tax/returns';
    }

    protected function exportKey(): ?string
    {
        return null;
    }

    protected function summaryStats(array $data): array
    {
        $items = collect($data['items'] ?? [])->filter(fn ($item): bool => is_array($item));
        $overdue = $items->filter(fn (array $item): bool => (bool) ($item['overdue'] ?? false));

        return [
            ['label' => 'Payable to KRA', 'value' => Format::money($data['payable'] ?? 0), 'description' => 'Outstanding across all months', 'icon' => 'heroicon-m-building-library', 'color' => ($data['payable'] ?? 0) > 0 ? 'warning' : 'success'],
            ['label' => 'Overdue Returns', 'value' => number_format($overdue->count()), 'description' => Format::money($overdue->sum(fn (array $item): float => (float) ($item['amount_to_remit'] ?? $item['outstanding'] ?? 0))).' past the '.$this->ordinal((int) ($data['filing_day'] ?? 20)), 'icon' => 'heroicon-m-exclamation-triangle', 'color' => $overdue->isNotEmpty() ? 'danger' : 'success'],
            ['label' => 'Tax Due', 'value' => Format::money($items->sum(fn (array $item): float => (float) ($item['tax_due'] ?? 0))), 'description' => 'Paid '.Format::money($items->sum(fn (array $item): float => (float) ($item['tax_remitted'] ?? 0))), 'icon' => 'heroicon-m-receipt-percent', 'color' => 'info'],
        ];
    }

    protected function columns(): array
    {
        $columns = collect(parent::columns())
            ->reject(fn (TextColumn $column): bool => in_array($column->getName(), ['gross_deposits', 'excise_due', 'excise_remitted'], true))
            ->values()
            ->all();

        array_splice($columns, 3, 0, [
            TextColumn::make('tax_due')
                ->label('Tax Due')
                ->alignEnd()
                ->weight('bold')
                ->description(fn (array $record): ?string => (float) ($record['tax_reversed'] ?? 0) > 0 ? 'Reversed '.Format::money($record['tax_reversed']) : null)
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('tax_remitted')
                ->label('Paid')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
        ]);

        return $columns;
    }

    protected function emptyHeading(): string
    {
        return 'No winnings tax returns in this period';
    }
}
