<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use App\Support\StatsFormat;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

/**
 * `/stats/promotions` `by_type`. Rows that are not a cost to the house (free-bet face value, referral
 * bonuses before payout) are greyed out and say so.
 */
class StatsPromotionTypesWidget extends TableWidget
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
        return collect((array) ($this->stats()['by_type'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && filled($row['type'] ?? null))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['type']])
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        $muted = fn (array $record): ?string => ($record['is_cost'] ?? true) ? null : 'gray';

        return $table
            ->heading('By type')
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('type')->label('Type')->weight('bold')->color($muted)
                    ->formatStateUsing(fn ($state): string => Str::headline((string) $state))
                    ->description(fn (array $record): string => ($record['is_cost'] ?? true)
                        ? (($record['scope'] ?? null) === 'product' ? 'Per product' : 'Platform')
                        : 'Information, not a cost'),
                TextColumn::make('amount')->label('Amount')->alignEnd()->color($muted)
                    ->state(fn (array $record): string => StatsFormat::money($record['amount'] ?? null)),
                TextColumn::make('items')->label('Items')->alignEnd()->color($muted)
                    ->state(fn (array $record): string => StatsFormat::count($record['items'] ?? null)),
                TextColumn::make('players')->label('Players')->alignEnd()->color($muted)
                    ->state(fn (array $record): string => StatsFormat::count($record['players'] ?? null)),
            ])
            ->paginated(false)
            ->emptyStateHeading(fn (): string => $this->statsProblem !== null ? 'Unavailable' : 'No promotions in this period')
            ->emptyStateDescription(fn (): ?string => $this->statsProblem);
    }
}
