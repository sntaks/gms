<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Livewire\ShopLoadsTable;
use App\Services\GameApiService;
use App\Support\Format;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Every cash load at a bet shop in the period, from `/finance/bet-shops/loads`, with
 * completed and reversed loads totalled apart. The player's wallet gets the gross
 * less 5% excise duty; the shop float pays the gross.
 */
class BetShopLoadsReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Bet Shop Loads';

    protected static ?int $navigationSort = 31;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::BET_SHOPS;

    protected ?string $heading = 'Bet Shop Loads';

    protected function reportKey(): string
    {
        return 'bet-shops/loads';
    }

    protected function exportKey(): ?string
    {
        return 'bet-shop-loads';
    }

    protected function extraFilters(): array
    {
        return [
            'status' => $this->filterValue('status'),
            'bet_shop_id' => $this->filterValue('bet_shop_id'),
            'customer_id' => $this->tableFilters['customer']['customer_id'] ?? null,
        ];
    }

    /**
     * Every shop as a filter option keyed by its id, closed ones included.
     *
     * @return array<int, string>
     */
    public static function shopFilterOptions(): array
    {
        try {
            $shops = app(GameApiService::class)->betShopOptions();
        } catch (\Throwable) {
            return [];
        }

        return collect($shops)->mapWithKeys(fn (array $shop): array => [$shop['id'] => $shop['shop_no'].' · '.$shop['name']])->all();
    }

    protected function summaryStats(array $data): array
    {
        $completed = $data['summary']['completed'] ?? [];
        $reversed = $data['summary']['reversed'] ?? [];

        return [
            ['label' => 'Cash Loaded', 'value' => Format::money($completed['gross_amount'] ?? 0), 'description' => number_format((int) ($completed['loads'] ?? 0)).' completed loads', 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
            ['label' => 'Excise Duty', 'value' => Format::money($completed['excise_amount'] ?? 0), 'description' => 'Owed to KRA; in the excise charges as shop load', 'icon' => 'heroicon-m-receipt-percent', 'color' => 'info'],
            ['label' => 'To Player Wallets', 'value' => Format::money($completed['net_amount'] ?? 0), 'icon' => 'heroicon-m-wallet', 'color' => 'primary'],
            ['label' => 'Reversed', 'value' => Format::money($reversed['gross_amount'] ?? 0), 'description' => number_format((int) ($reversed['loads'] ?? 0)).' loads reversed by Kadi Kings', 'icon' => 'heroicon-m-arrow-uturn-left', 'color' => (int) ($reversed['loads'] ?? 0) > 0 ? 'warning' : 'gray'],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('loaded_at')
                ->label('Loaded')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                ->description(fn (array $record): ?string => Format::apiActor($record['loaded_by'] ?? null)),
            TextColumn::make('load_id')
                ->label('Load')
                ->fontFamily('mono')
                ->formatStateUsing(fn (?string $state): string => Str::limit((string) $state, 8, '…'))
                ->tooltip(fn (array $record): ?string => $record['load_id'] ?? null)
                ->copyable()
                ->copyableState(fn (array $record): ?string => $record['load_id'] ?? null)
                ->url(fn (array $record): ?string => static::loadUrl($record['id'] ?? null)),
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
            TextColumn::make('gross_amount')
                ->label('Cash paid')
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('excise_amount')
                ->label('Excise')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('net_amount')
                ->label('To wallet')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => ShopLoadsTable::statusColor($state))
                ->formatStateUsing(fn (?string $state): string => ShopLoadsTable::STATUSES[$state] ?? ucfirst((string) $state)),
            TextColumn::make('reversed_at')
                ->label('Reversed')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                ->placeholder('—')
                ->description(fn (array $record): ?string => $record['reversal_reason'] ?? null),
        ];
    }

    /**
     * The Shop Loads page with this load open, for whoever may view bet shops.
     */
    public static function loadUrl(int|string|null $loadId): ?string
    {
        return filled($loadId) && ShopLoadsPage::canAccess() ? ShopLoadsPage::getUrl(['load' => (int) $loadId]) : null;
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('status')->options(ShopLoadsTable::STATUSES),
            SelectFilter::make('bet_shop_id')
                ->label('Shop')
                ->options(fn (): array => static::shopFilterOptions())
                ->searchable(),
            Filter::make('customer')
                ->schema([TextInput::make('customer_id')->label('Customer ID')->integer()->minValue(1)])
                ->indicateUsing(fn (array $data): ?string => filled($data['customer_id'] ?? null) ? 'Customer #'.$data['customer_id'] : null),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No cash loads in this period';
    }
}
