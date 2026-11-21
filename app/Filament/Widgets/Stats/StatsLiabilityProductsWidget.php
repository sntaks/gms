<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsLiability;
use App\Support\StatsFilters;
use App\Support\StatsFormat;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * `/stats/liability` `by_product`: what is open now per product. Prediction markets report the worst
 * side (computed by KadiApi); other games report a potential payout per wager when they send one, so
 * `wagers_with_potential_payout` shows how complete that figure is.
 */
class StatsLiabilityProductsWidget extends TableWidget
{
    use ReadsLiability;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

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
        return $table
            ->heading('Open now, by product')
            ->description(function (): string {
                $stats = $this->stats();

                return $this->statsProblem ?? 'Open wagers '.StatsFormat::count($stats['open_wagers'] ?? null)
                    .' · stakes '.StatsFormat::money($stats['open_stakes'] ?? null)
                    .' · potential payout '.StatsFormat::money($stats['open_potential_payout'] ?? null)
                    .'. Refreshes every minute.';
            })
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('product')
                    ->label('Product')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => StatsFilters::productName($state))
                    ->description(fn (array $record): string => StatsFilters::gameName($record['game'] ?? null)),
                TextColumn::make('open_wagers')->label('Open wagers')->alignEnd()->tooltip(StatsFormat::tooltip('open_wagers'))
                    ->state(fn (array $record): string => StatsFormat::count($record['open_wagers'] ?? null)),
                TextColumn::make('open_stakes')->label('Open stakes')->alignEnd()->tooltip(StatsFormat::tooltip('open_stakes'))
                    ->state(fn (array $record): string => StatsFormat::money($record['open_stakes'] ?? null)),
                TextColumn::make('open_free_bet_value')->label('Free bets open')->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::money($record['open_free_bet_value'] ?? null)),
                TextColumn::make('open_potential_payout')->label('Potential payout')->alignEnd()->tooltip(StatsFormat::tooltip('open_potential_payout'))
                    ->state(fn (array $record): string => StatsFormat::money($record['open_potential_payout'] ?? null)),
                TextColumn::make('potential_payout_basis')
                    ->label('Basis')
                    ->badge()
                    ->color(fn ($state): string => $state === 'worst_side' ? 'info' : 'gray')
                    ->formatStateUsing(fn ($state): string => $state === 'worst_side' ? 'Worst side' : 'Reported by the game')
                    ->description(fn (array $record): ?string => ($record['potential_payout_basis'] ?? null) === 'reported'
                        ? StatsFormat::count($record['wagers_with_potential_payout'] ?? 0).' of '.StatsFormat::count($record['open_wagers'] ?? 0).' wagers report one'
                        : null),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(fn (): string => $this->statsProblem !== null ? 'Unavailable' : 'Nothing open')
            ->emptyStateDescription(fn (): ?string => $this->statsProblem);
    }
}
