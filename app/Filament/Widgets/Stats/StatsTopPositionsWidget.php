<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Pages\PredictMarketDetailPage;
use App\Filament\Widgets\Stats\Concerns\ReadsLiability;
use App\Support\StatsFilters;
use App\Support\StatsFormat;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

/**
 * `/stats/liability` `top_positions`: the ten largest open positions. They carry no player details.
 */
class StatsTopPositionsWidget extends TableWidget
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
        return collect((array) ($this->stats()['top_positions'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && filled($row['reference'] ?? null))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['reference']])
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Largest open positions')
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('reference')->label('Reference')->fontFamily('mono')->copyable(),
                TextColumn::make('product')->label('Product')
                    ->formatStateUsing(fn ($state): string => StatsFilters::productName($state)),
                TextColumn::make('stake')->label('Stake')->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::money($record['stake'] ?? null))
                    ->description(fn (array $record): ?string => (float) ($record['free_bet_amount'] ?? 0) > 0 ? 'Free bet '.StatsFormat::money($record['free_bet_amount']) : null),
                TextColumn::make('potential_payout')->label('Potential payout')->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::money($record['potential_payout'] ?? null)),
                TextColumn::make('predict_market_id')
                    ->label('Market and side')
                    ->state(fn (array $record): string => filled($record['predict_market_id'] ?? null)
                        ? 'Market #'.$record['predict_market_id'].(filled($record['side'] ?? null) ? ' · '.Str::headline((string) $record['side']) : '')
                        : (filled($record['side'] ?? null) ? Str::headline((string) $record['side']) : '—'))
                    ->url(fn (array $record): ?string => filled($record['predict_market_id'] ?? null) && PredictMarketDetailPage::canAccess()
                        ? PredictMarketDetailPage::getUrl(['market' => (int) $record['predict_market_id']])
                        : null),
                TextColumn::make('placed_at')->label('Placed at')
                    ->state(fn (array $record): string => StatsFormat::time($record['placed_at'] ?? null)),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(fn (): string => $this->statsProblem !== null ? 'Unavailable' : 'Nothing open')
            ->emptyStateDescription(fn (): ?string => $this->statsProblem);
    }
}
