<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\GameReports\GameReportsCluster;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * This week's winners across single games and competitions combined. The API
 * fixes this leaderboard to the current week, so there is no period filter.
 */
class OverallLeaderboard extends BaseReportPage implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationLabel = 'Overall Leaderboard';

    protected static ?string $cluster = GameReportsCluster::class;

    protected static ?int $navigationSort = 3;

    protected ?string $heading = 'Overall Leaderboard (This Week)';

    protected string $view = 'filament.pages.singles-leaderboard';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage, ?string $search, ?string $sortColumn, ?string $sortDirection): LengthAwarePaginator => ApiTablePaginator::make(
                response: $this->fetchRecords(),
                page: $page,
                perPage: $recordsPerPage,
                search: $search,
                searchKeys: ['name'],
                sortColumn: $sortColumn,
                sortDirection: $sortDirection,
            ))
            ->columns([
                TextColumn::make('name')
                    ->label('Player Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('single_game_wins')
                    ->label('Single Games')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('competition_wins')
                    ->label('Competitions')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('total_wins')
                    ->label('Total Winnings')
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
            ])
            ->defaultSort('total_wins', 'desc')
            ->emptyStateIcon('heroicon-o-trophy')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Leaderboard unavailable' : 'No leaderboard data')
            ->emptyStateDescription(fn (): string => $this->apiError
                ? 'The wallet API could not be reached. Refresh the page to try again.'
                : 'No winnings recorded this week.')
            ->striped();
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function fetchRecords(): array
    {
        try {
            $response = Cache::remember(
                'overall_leaderboard_'.now()->format('o-W'),
                300,
                fn (): array => app(GameApiService::class)->getCombinedLeaderboard(),
            );
            $this->apiError = false;

            return $response['leaderboard'] ?? [];
        } catch (\Throwable) {
            $this->apiError = true;

            return [];
        }
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
