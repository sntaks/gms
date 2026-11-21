<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsDashboardStats;
use App\Support\StatsFilters;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

/**
 * `/stats/dashboard` `by_product`: every product's figures for the period plus what is open on it now.
 * Kadi rake products have no payouts or RTP, shown as "n/a".
 */
class StatsByProductWidget extends TableWidget
{
    use ReadsDashboardStats;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(): array
    {
        return collect((array) ($this->dashboard()['by_product'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && filled($row['product'] ?? null))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['product']])
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('By product')
            ->description('Open wagers, stakes and potential payout are what is open now, not in the period.')
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('product')
                    ->label('Product')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => StatsFilters::productName($state))
                    ->description(fn (array $record): string => StatsFilters::gameName($record['game'] ?? null)),
                TextColumn::make('revenue_model')
                    ->label('Revenue model')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state): string => Str::headline((string) $state)),
                ...StatsByGameWidget::figureColumns([
                    'players' => 'count', 'bets' => 'count', 'voids' => 'count', 'turnover' => 'money', 'payouts' => 'money',
                    'ggr' => 'money', 'hold' => 'percent', 'rtp' => 'percent', 'avg_stake' => 'money', 'promotions' => 'money',
                    'net' => 'money', 'open_wagers' => 'count', 'open_stakes' => 'money', 'open_potential_payout' => 'money',
                ]),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateHeading(fn (): string => $this->statsProblem !== null ? 'Unavailable' : 'No play in this period')
            ->emptyStateDescription(fn (): ?string => $this->statsProblem);
    }
}
