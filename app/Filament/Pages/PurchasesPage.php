<?php

namespace App\Filament\Pages;

use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use UnitEnum;

class PurchasesPage extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Purchases';

    protected static ?string $cluster = null;

    protected static string|UnitEnum|null $navigationGroup = '📊 Finance';

    protected static ?int $navigationSort = 4;

    protected ?string $heading = 'Purchases';

    protected function reportKey(): string
    {
        return 'purchases';
    }

    protected function exportKey(): ?string
    {
        return 'purchases';
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];

        return [
            ['label' => 'Purchases', 'value' => number_format((int) ($summary['purchases'] ?? 0)), 'description' => 'Loads, gifts and emojis', 'icon' => 'heroicon-m-shopping-cart', 'color' => 'primary'],
            ['label' => 'Total Paid', 'value' => Format::money($summary['amount'] ?? 0), 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
        ];
    }

    protected function blocks(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $money = fn ($value): string => Format::money($value);

        return [
            [
                'title' => 'By type',
                'headers' => ['Type', 'Purchases', 'Amount'],
                'rows' => collect($summary['by_type'] ?? [])
                    ->map(fn (array $row, $key): array => [
                        ucfirst((string) ($row['type'] ?? (is_string($key) ? $key : '—'))),
                        number_format((int) ($row['purchases'] ?? $row['count'] ?? 0)),
                        $money($row['amount'] ?? 0),
                    ])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Trend',
                'description' => 'Per '.($data['meta']['period']['group_by'] ?? 'day'),
                'headers' => ['Period', 'Loads', 'Gifts', 'Emojis', 'Total'],
                'rows' => collect($summary['series'] ?? [])
                    ->map(fn (array $row): array => [
                        $row['period'] ?? '—',
                        $money($row['load'] ?? 0),
                        $money($row['gift'] ?? 0),
                        $money($row['emoji'] ?? 0),
                        $money($row['total'] ?? 0),
                    ])
                    ->all(),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('created_at')
                ->label('Date')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('customer_name')
                ->label('Player')
                ->weight('bold')
                ->placeholder('—'),
            TextColumn::make('type')
                ->badge()
                ->color('info')
                ->state(fn (array $record): ?string => $record['type'] ?? $record['purchase_type'] ?? null)
                ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
            TextColumn::make('amount')
                ->label('Amount')
                ->weight('bold')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No purchases found';
    }
}
