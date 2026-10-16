<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\GameReports\GameReportsCluster;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SinglesLeaderboard extends BaseReportPage implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Singles Leaderboard';

    protected static ?string $cluster = GameReportsCluster::class;

    protected static ?int $navigationSort = 1;

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
                TextColumn::make('wins')
                    ->label('Total Winnings')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
            ])
            ->emptyStateIcon('heroicon-o-chart-bar-square')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Leaderboard unavailable' : 'No leaderboard data')
            ->emptyStateDescription(fn (): string => $this->apiError
                ? 'The wallet API could not be reached. Refresh the page to try again.'
                : 'No singles game data for the selected period.')
            ->striped();
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function fetchRecords(): array
    {
        [$start, $end] = $this->dateRange();
        $start = Carbon::now()->subMonths(3)->startOfMonth()->format('Y-m-d');
        $end = Carbon::now()->endOfMonth()->format('Y-m-d');

        try {
            $response = Cache::remember(
                "singles_leaderboard_{$start}_{$end}",
                300,
                fn (): array => app(GameApiService::class)->getLeaderboard($start, $end),
            );
            $this->apiError = false;

            return $response['single_leaderboard'] ?? $response['data'] ?? [];
        } catch (\Throwable) {
            $this->apiError = true;

            return [];
        }
    }

    protected function onFilterApplied(): void
    {
        $this->resetTable();
    }
}
