<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsDashboardStats;
use App\Support\StatsFilters;
use App\Support\StatsFormat;
use Filament\Actions\Action;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * `/stats/dashboard` `by_game`: one row per game with a GGR sparkline and its change. Clicking a row
 * filters the whole dashboard by that game.
 */
class StatsByGameWidget extends TableWidget
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
        return collect((array) ($this->dashboard()['by_game'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && filled($row['game'] ?? null))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['game']])
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('By game')
            ->description('Click a game to filter the dashboard by it.')
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('game')
                    ->label('Game')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => StatsFilters::gameName($state)),
                ...self::figureColumns(['players' => 'count', 'bets' => 'count', 'turnover' => 'money', 'ggr' => 'money', 'hold' => 'percent', 'rtp' => 'percent', 'net' => 'money']),
                ViewColumn::make('series')
                    ->label('GGR trend')
                    ->view('filament.widgets.stats.sparkline-column'),
                TextColumn::make('delta_pct.ggr')
                    ->label('GGR change')
                    ->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::delta($record['delta_pct']['ggr'] ?? null)['label'])
                    ->color(fn (array $record): string => StatsFormat::delta($record['delta_pct']['ggr'] ?? null)['color']),
            ])
            ->recordActions([
                Action::make('filterByGame')
                    ->label('Filter')
                    ->icon('heroicon-o-funnel')
                    ->iconButton()
                    ->tooltip('Filter the dashboard by this game')
                    ->action(fn (array $record) => $this->dispatch('stats-filter-game', game: (string) $record['game'])),
            ])
            ->recordAction('filterByGame')
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-puzzle-piece')
            ->emptyStateHeading(fn (): string => $this->statsProblem !== null ? 'Unavailable' : 'No play in this period')
            ->emptyStateDescription(fn (): ?string => $this->statsProblem);
    }

    /**
     * Right-aligned figure columns with the API's definitions as tooltips; null shows "n/a".
     *
     * @param  array<string, string>  $figures  key => money|percent|count
     * @return list<TextColumn>
     */
    public static function figureColumns(array $figures): array
    {
        $labels = [
            'players' => 'Players', 'bets' => 'Bets', 'voids' => 'Voids', 'turnover' => 'Turnover', 'payouts' => 'Payouts',
            'ggr' => 'GGR', 'hold' => 'Hold %', 'rtp' => 'RTP %', 'avg_stake' => 'Avg stake', 'promotions' => 'Promotions',
            'net' => 'Net', 'open_wagers' => 'Open wagers', 'open_stakes' => 'Open stakes', 'open_potential_payout' => 'Open potential payout',
        ];

        return collect($figures)
            ->map(fn (string $kind, string $key): TextColumn => TextColumn::make($key)
                ->label($labels[$key] ?? $key)
                ->alignEnd()
                ->extraAttributes(['style' => 'white-space: nowrap'])
                ->tooltip(StatsFormat::tooltip($key))
                ->state(fn (array $record): string => match ($kind) {
                    'money' => StatsFormat::money($record[$key] ?? null),
                    'percent' => StatsFormat::percent($record[$key] ?? null),
                    default => StatsFormat::count($record[$key] ?? null),
                }))
            ->values()
            ->all();
    }
}
