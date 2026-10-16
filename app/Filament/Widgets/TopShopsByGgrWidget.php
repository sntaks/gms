<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\BetShopsReport;
use App\Filament\Pages\FinanceReportPage;
use App\Livewire\ShopLoadsTable;
use App\Support\Format;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The bet shops with the highest gross gaming revenue this month, from the same
 * cached `/finance/bet-shops` call pattern as {@see BetShopsFinanceWidget}. GGR is
 * shown in red when players at a shop won more than they staked.
 */
class TopShopsByGgrWidget extends TableWidget
{
    public const LIMIT = 5;

    protected static ?int $sort = 29;

    protected int|string|array $columnSpan = 'full';

    public ?string $problem = null;

    public static function canView(): bool
    {
        return FinanceReportPage::canAccess();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function topShops(): array
    {
        $report = BetShopsFinanceWidget::shopsReport(today()->startOfMonth()->toDateString(), today()->toDateString());
        $this->problem = BetShopsFinanceWidget::unavailableMessage($report);

        return collect($report['data']['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['bet_shop_id']))
            ->sortByDesc(fn (array $row): float => (float) ($row['ggr'] ?? 0))
            ->take(self::LIMIT)
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['bet_shop_id']])
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top Bet Shops by GGR This Month')
            ->description(BetShopsReport::GGR_TOOLTIP)
            ->records(fn (): array => $this->topShops())
            ->columns([
                TextColumn::make('shop_no')
                    ->label('Shop')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->description(fn (array $record): ?string => $record['name'] ?? null)
                    ->url(fn (array $record): ?string => ShopLoadsTable::shopUrl($record['bet_shop_id'] ?? null)),
                TextColumn::make('ggr')
                    ->label('GGR')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($state): ?string => BetShopsReport::signedMoneyColor($state))
                    ->tooltip(BetShopsReport::GGR_TOOLTIP)
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('game_stakes')
                    ->label('Game stakes')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('loads_gross')
                    ->label('Cash loaded')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('float_balance')
                    ->label('Float now')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-building-storefront')
            ->emptyStateHeading(fn (): string => $this->problem !== null ? 'Unavailable' : 'No shop activity this month')
            ->emptyStateDescription(fn (): ?string => $this->problem);
    }
}
