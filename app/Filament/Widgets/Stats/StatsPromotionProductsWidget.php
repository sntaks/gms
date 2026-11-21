<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use App\Support\StatsFilters;
use App\Support\StatsFormat;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * `/stats/promotions` `by_product`: free bets and wager bonuses per product. Cost is free-bet winnings
 * plus wager bonuses; the free-bet face value is shown for information.
 */
class StatsPromotionProductsWidget extends TableWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected function statsReportKey(): string
    {
        return 'promotions';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(): array
    {
        return collect((array) ($this->stats()['by_product'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && filled($row['product'] ?? null))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['product']])
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        $money = fn (string $key, string $label): TextColumn => TextColumn::make($key)->label($label)->alignEnd()
            ->state(fn (array $record): string => StatsFormat::money($record[$key] ?? null));

        return $table
            ->heading('By product')
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('product')->label('Product')->weight('bold')
                    ->formatStateUsing(fn ($state): string => StatsFilters::productName($state))
                    ->description(fn (array $record): string => StatsFilters::gameName($record['game'] ?? null)),
                $money('cost', 'Cost')->weight('bold'),
                $money('free_bet_winnings', 'Free-bet winnings'),
                $money('wager_bonus', 'Wager bonuses'),
                $money('free_bet_value', 'Free-bet face value')->color('gray')->tooltip('Information, not a cost.'),
                TextColumn::make('players')->label('Players')->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::count($record['players'] ?? null)),
            ])
            ->paginated(false)
            ->emptyStateHeading(fn (): string => $this->statsProblem !== null ? 'Unavailable' : 'No product promotions in this period')
            ->emptyStateDescription(fn (): ?string => $this->statsProblem);
    }
}
