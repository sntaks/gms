<?php

namespace App\Filament\Pages;

use App\Support\Format;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

class LedgerReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Ledger Explorer';

    protected static ?int $navigationSort = 20;

    protected ?string $heading = 'Ledger Explorer';

    protected function reportKey(): string
    {
        return 'ledger';
    }

    protected function exportKey(): ?string
    {
        return 'ledger';
    }

    protected function extraFilters(): array
    {
        return [
            'category' => $this->filterValue('category'),
            'wallet_type' => $this->filterValue('wallet_type'),
            'status' => $this->filterValue('status'),
            'customer_id' => $this->tableFilters['customer']['customer_id'] ?? null,
            'product' => $this->filterValue('product'),
        ];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];

        return [
            ['label' => 'Ledger Entries', 'value' => number_format((int) ($summary['entries'] ?? 0)), 'icon' => 'heroicon-m-book-open', 'color' => 'primary'],
            ['label' => 'Total Debits', 'value' => Format::money($summary['debit'] ?? 0), 'icon' => 'heroicon-m-arrow-trending-up', 'color' => 'info'],
            ['label' => 'Total Credits', 'value' => Format::money($summary['credit'] ?? 0), 'icon' => 'heroicon-m-arrow-trending-down', 'color' => 'info'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'By entry type',
                'headers' => ['Entry type', 'Category', 'Entries', 'Debit', 'Credit'],
                'rows' => collect($data['summary']['by_entry_type'] ?? [])
                    ->map(fn (array $row): array => [
                        str((string) ($row['entry_type'] ?? '—'))->replace('_', ' ')->title()->toString(),
                        str((string) ($row['category'] ?? '—'))->replace('_', ' ')->title()->toString(),
                        number_format((int) ($row['entries'] ?? 0)),
                        Format::money($row['debit'] ?? 0),
                        Format::money($row['credit'] ?? 0),
                    ])
                    ->all(),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('created_at')
                ->label('Time')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('entry_type')
                ->label('Entry')
                ->badge()
                ->color('info')
                ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->title()->toString()),
            TextColumn::make('category')
                ->color('gray')
                ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->title()->toString()),
            TextColumn::make('account')
                ->color('gray'),
            TextColumn::make('wallet_id')
                ->label('Wallet')
                ->state(fn (array $record): string => ($record['wallet_type'] ?? '—').' #'.($record['wallet_id'] ?? '—')),
            TextColumn::make('customer_id')
                ->label('Customer')
                ->placeholder('—'),
            TextColumn::make('debit')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => (float) $state > 0 ? Format::money($state) : '—'),
            TextColumn::make('credit')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => (float) $state > 0 ? Format::money($state) : '—'),
            TextColumn::make('balance_after')
                ->label('Balance After')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => $state === 'reversed' ? 'danger' : 'success')
                ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
            TextColumn::make('reference')
                ->color('gray')
                ->copyable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            // api config finance.entry_types categories and LedgerEntry wallet types.
            SelectFilter::make('category')->options(collect([
                'cash_in', 'cash_out', 'stake', 'payout', 'house_revenue', 'refund', 'adjustment', 'transfer', 'escrow_movement', 'coin',
                'tax_withheld', 'referral_bonus', 'referral_payout', 'promotion', 'capital', 'shop_float', 'shop_load', 'shop_cash_out', 'house_banked_win',
            ])->mapWithKeys(fn (string $key): array => [$key => str($key)->replace('_', ' ')->title()->toString()])->all()),
            SelectFilter::make('wallet_type')->options([
                'wallet' => 'Customer wallet',
                'game_wallet' => 'Game wallet',
                'competition_wallet' => 'Competition wallet',
                'coin_wallet' => 'Coin wallet',
                'dispute' => 'Dispute escrow',
                'referral' => 'Referral wallet',
                'bet_shop' => 'Bet shop float',
                'shop_withdrawal' => 'Shop withdrawal',
                'wager' => 'Open wagers',
            ]),
            SelectFilter::make('product')->options(fn (): array => IncomeStatementReport::productOptions()),
            SelectFilter::make('status')->options(['settled' => 'Settled', 'reversed' => 'Reversed']),
            Filter::make('customer')
                ->schema([TextInput::make('customer_id')->label('Customer ID')->numeric()])
                ->indicateUsing(fn (array $data): ?string => filled($data['customer_id'] ?? null) ? 'Customer #'.$data['customer_id'] : null),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No ledger entries found';
    }
}
