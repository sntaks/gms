<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Livewire\ShopLoadsTable;
use App\Livewire\ShopWithdrawalsTable;
use App\Support\Format;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Every bet shop cash withdrawal asked for in the period, from
 * `/finance/bet-shops/withdrawals`, counted by status. A pending one is money still
 * owed to the player (`shop_withdrawals` on the balance sheet); a paid one came out
 * of the shop's till and went onto its float.
 */
class BetShopWithdrawalsReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Bet Shop Withdrawals';

    protected static ?int $navigationSort = 32;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::BET_SHOPS;

    protected ?string $heading = 'Bet Shop Withdrawals';

    protected function reportKey(): string
    {
        return 'bet-shops/withdrawals';
    }

    protected function exportKey(): ?string
    {
        return 'bet-shop-withdrawals';
    }

    protected function extraFilters(): array
    {
        return [
            'status' => $this->filterValue('status'),
            'bet_shop_id' => $this->filterValue('bet_shop_id'),
            'customer_id' => $this->tableFilters['customer']['customer_id'] ?? null,
        ];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $stat = fn (string $status): array => is_array($summary[$status] ?? null) ? $summary[$status] : [];

        return [
            ['label' => 'Paid Out', 'value' => Format::money($stat('paid')['amount'] ?? 0), 'description' => number_format((int) ($stat('paid')['withdrawals'] ?? 0)).' withdrawals collected at the counter', 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
            ['label' => 'Pending', 'value' => Format::money($stat('pending')['amount'] ?? 0), 'description' => number_format((int) ($stat('pending')['withdrawals'] ?? 0)).' waiting to be collected', 'icon' => 'heroicon-m-clock', 'color' => 'warning'],
            ['label' => 'Returned to Wallets', 'value' => Format::money(collect(['cancelled', 'rejected', 'expired'])->sum(fn (string $status): float => (float) ($stat($status)['amount'] ?? 0))), 'description' => 'Cancelled, rejected or expired', 'icon' => 'heroicon-m-arrow-uturn-left', 'color' => 'gray'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'By status',
                'headers' => ['Status', 'Withdrawals', 'Amount'],
                'rows' => collect($data['summary'] ?? [])
                    ->filter(fn ($row): bool => is_array($row))
                    ->map(fn (array $row, string $status): array => [
                        ShopWithdrawalsTable::STATUSES[$status] ?? ucfirst($status),
                        number_format((int) ($row['withdrawals'] ?? 0)),
                        Format::money($row['amount'] ?? 0),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('requested_at')
                ->label('Requested')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('withdrawal_id')
                ->label('Withdrawal')
                ->fontFamily('mono')
                ->formatStateUsing(fn (?string $state): string => Str::limit((string) $state, 8, '…'))
                ->tooltip(fn (array $record): ?string => $record['withdrawal_id'] ?? null)
                ->copyable()
                ->copyableState(fn (array $record): ?string => $record['withdrawal_id'] ?? null),
            TextColumn::make('shop_no')
                ->label('Shop')
                ->fontFamily('mono')
                ->description(fn (array $record): ?string => $record['shop_name'] ?? null)
                ->url(fn (array $record): ?string => ShopLoadsTable::shopUrl($record['bet_shop_id'] ?? null)),
            TextColumn::make('customer_name')
                ->label('Player')
                ->placeholder('—')
                ->description(fn (array $record): string => Format::playerNo($record['player_no'] ?? null))
                ->url(fn (array $record): ?string => ReferralWithdrawalsPage::customerUrl($record['customer_id'] ?? null)),
            TextColumn::make('amount')
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state, array $record): string => ShopWithdrawalsTable::isStale($record) ? 'danger' : ShopWithdrawalsTable::statusColor($state))
                ->tooltip(fn (array $record): ?string => ShopWithdrawalsTable::isStale($record) ? ShopWithdrawalsTable::STALE_WARNING : null)
                ->formatStateUsing(fn (?string $state): string => ShopWithdrawalsTable::STATUSES[$state] ?? ucfirst((string) $state)),
            TextColumn::make('failed_attempts')
                ->label('Wrong codes')
                ->alignEnd()
                ->numeric(),
            TextColumn::make('closed_at')
                ->label('Closed')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                ->placeholder('—')
                ->description(fn (array $record): ?string => collect([Format::apiActor($record['closed_by'] ?? null), $record['close_reason'] ?? null])->filter()->implode(' · ') ?: null),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('status')->options(ShopWithdrawalsTable::STATUSES),
            SelectFilter::make('bet_shop_id')
                ->label('Shop')
                ->options(fn (): array => BetShopLoadsReport::shopFilterOptions())
                ->searchable(),
            Filter::make('customer')
                ->schema([TextInput::make('customer_id')->label('Customer ID')->integer()->minValue(1)])
                ->indicateUsing(fn (array $data): ?string => filled($data['customer_id'] ?? null) ? 'Customer #'.$data['customer_id'] : null),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No shop withdrawals in this period';
    }
}
