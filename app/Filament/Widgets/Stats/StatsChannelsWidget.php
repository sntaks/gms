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
 * `/stats/players` `channel`: where play came from (web, app, bet shop, …).
 */
class StatsChannelsWidget extends TableWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected function statsReportKey(): string
    {
        return 'players';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(): array
    {
        return collect((array) ($this->stats()['channel'] ?? []))
            ->filter(fn ($row): bool => is_array($row))
            ->values()
            ->map(fn (array $row, int $index): array => [...$row, ArrayRecord::getKeyName() => (string) ($row['channel'] ?? $index)])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Channels')
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('channel')->label('Channel')->weight('bold')
                    ->formatStateUsing(fn ($state): string => filled($state) ? Str::headline((string) $state) : 'Unknown'),
                TextColumn::make('entries')->label('Entries')->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::count($record['entries'] ?? null)),
                TextColumn::make('stakes')->label('Stakes')->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::money($record['stakes'] ?? null)),
                TextColumn::make('player_days')->label('Player-days')->alignEnd()
                    ->tooltip('Each player counted once per day they played on the channel.')
                    ->state(fn (array $record): string => StatsFormat::count($record['player_days'] ?? null)),
            ])
            ->paginated(false)
            ->emptyStateHeading(fn (): string => $this->statsProblem !== null ? 'Unavailable' : 'No play in this period')
            ->emptyStateDescription(fn (): ?string => $this->statsProblem);
    }
}
