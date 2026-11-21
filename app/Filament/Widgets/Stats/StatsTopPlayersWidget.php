<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Pages\CustomerStatementReport;
use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use App\Support\Format;
use App\Support\StatsFilters;
use App\Support\StatsFormat;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * `/stats/players/top`: the players ranked by the chosen figure. A row opens the player's statement.
 */
class StatsTopPlayersWidget extends TableWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function statsReportKey(): string
    {
        return 'players/top';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(): array
    {
        return collect((array) ($this->stats()['players'] ?? []))
            ->filter(fn ($row): bool => is_array($row) && filled($row['customer_id'] ?? null))
            ->values()
            ->map(fn (array $row, int $index): array => [...$row, 'rank' => $index + 1, ArrayRecord::getKeyName() => (string) $row['customer_id']])
            ->all();
    }

    public function table(Table $table): Table
    {
        $money = fn (string $key, string $label): TextColumn => TextColumn::make($key)->label($label)->alignEnd()
            ->tooltip(StatsFormat::tooltip($key))
            ->state(fn (array $record): string => StatsFormat::money($record[$key] ?? null));

        return $table
            ->heading(fn (): string => 'Top players by '.(StatsFilters::SORTS[$this->stats()['sort'] ?? ''] ?? 'turnover'))
            ->description('Kadi play adds nothing to a player\'s GGR: the Kadi house cut is not attributed to players, so GGR here is from house-banked games only.')
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('rank')->label('#')->alignEnd(),
                TextColumn::make('display_name')->label('Player')->weight('bold')
                    ->placeholder('—')
                    ->description(fn (array $record): string => Format::playerNo($record['player_no'] ?? null)),
                $money('turnover', 'Turnover'),
                $money('payouts', 'Payouts'),
                $money('net_loss', 'Net loss')->tooltip('Turnover minus payouts.'),
                $money('ggr', 'GGR'),
                TextColumn::make('wins')->label('Wins')->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::count($record['wins'] ?? null)),
                $money('deposits', 'Deposits'),
                $money('withdrawals', 'Withdrawals'),
                TextColumn::make('games_played')->label('Games played')->alignEnd()
                    ->state(fn (array $record): string => StatsFormat::count($record['games_played'] ?? null)),
                TextColumn::make('last_active')->label('Last active')
                    ->state(fn (array $record): string => Format::date($record['last_active'] ?? null)),
            ])
            ->recordUrl(fn (array $record): string => CustomerStatementReport::getUrl(['customerId' => (int) $record['customer_id']]))
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateHeading(fn (): string => $this->statsProblem !== null ? 'Unavailable' : 'No play in this period')
            ->emptyStateDescription(fn (): ?string => $this->statsProblem);
    }
}
