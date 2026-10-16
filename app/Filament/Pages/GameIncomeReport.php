<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\GameReports\GameReportsCluster;
use App\Filament\Pages\Widgets\IncomeSummaryWidget;
use App\Services\GameApiService;
use App\Support\Format;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GameIncomeReport extends BaseReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Income Report';

    protected static ?string $cluster = GameReportsCluster::class;

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.game-income-report';

    /**
     * Per-request memo so the report is built once even though the widget,
     * infolist, and page all read it.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $cachedReport = null;

    public bool $isLoading = false;

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            IncomeSummaryWidget::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'summary' => $this->getReportData()['totals'],
        ];
    }

    /**
     * Income breakdown grouped by game category. The structural shape is shared
     * by the summary widget and the breakdown infolist below.
     *
     * @return array{
     *     singles: array<int, mixed>,
     *     tournaments: array<int, mixed>,
     *     jackpots: array<int, mixed>,
     *     totals: array{singles_income: float, tournament_income: float, jackpot_income: float, grand_total: float}
     * }
     */
    public function getReportData(): array
    {
        return $this->cachedReport ??= $this->buildReportData();
    }

    public function reportInfolist(Schema $schema): Schema
    {
        return $schema
            ->constantState(fn (): array => [
                'singles' => $this->mapSingles($this->getReportData()['singles']),
                'tournaments' => $this->mapRounds($this->getReportData()['tournaments']),
                'jackpots' => $this->mapJackpots($this->getReportData()['jackpots']),
            ])
            ->components([
                $this->breakdownSection(
                    'Singles Games',
                    'singles',
                    'heroicon-o-user-group',
                    'success',
                    fn () => $this->getReportData()['totals']['singles_income'],
                    'Group Size',
                ),
                $this->breakdownSection(
                    'Tournaments',
                    'tournaments',
                    'heroicon-o-trophy',
                    'info',
                    fn () => $this->getReportData()['totals']['tournament_income'],
                    'Rounds Played',
                ),
                $this->breakdownSection(
                    'Jackpots',
                    'jackpots',
                    'heroicon-o-sparkles',
                    'warning',
                    fn () => $this->getReportData()['totals']['jackpot_income'],
                    'Tier',
                ),
            ]);
    }

    /**
     * @return array{
     *     singles: array<int, mixed>,
     *     tournaments: array<int, mixed>,
     *     jackpots: array<int, mixed>,
     *     totals: array{singles_income: float, tournament_income: float, jackpot_income: float, grand_total: float}
     * }
     */
    protected function buildReportData(): array
    {
        [$start, $end] = $this->dateRange();
        $gameApi = app(GameApiService::class);

        $startDate = $start ?? '2000-01-01';
        $endDate = $end ?? now()->toDateString();

        \Log::info('GameIncomeReport fetching data', [
            'period' => $this->period,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            $singles = $gameApi->getGameIncomeBreakdown($startDate, $endDate);
            $tournaments = $gameApi->getCompetitionIncomeBreakdown(1, $startDate, $endDate);
            $jackpots = $gameApi->getCompetitionIncomeBreakdown(2, $startDate, $endDate);

            \Log::info('GameIncomeReport API response', [
                'singles_count' => count($singles),
                'tournaments_count' => count($tournaments),
                'jackpots_count' => count($jackpots),
            ]);

            $data = [
                'singles' => $singles,
                'tournaments' => $tournaments,
                'jackpots' => $jackpots,
            ];

            $this->apiError = false;
        } catch (\Throwable $e) {
            \Log::error('GameIncomeReport API error', [
                'period' => $this->period,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage(),
            ]);
            $this->apiError = true;
            $data = ['singles' => [], 'tournaments' => [], 'jackpots' => []];
        }

        $singlesIncome = (float) collect($data['singles'])->sum('total_income');
        $tournamentIncome = (float) collect($data['tournaments'])->sum('total_income');
        $jackpotIncome = (float) collect($data['jackpots'])->sum('total_income');

        return [
            'singles' => $data['singles'],
            'tournaments' => $data['tournaments'],
            'jackpots' => $data['jackpots'],
            'totals' => [
                'singles_income' => $singlesIncome,
                'tournament_income' => $tournamentIncome,
                'jackpot_income' => $jackpotIncome,
                'grand_total' => $singlesIncome + $tournamentIncome + $jackpotIncome,
            ],
        ];
    }

    /**
     * One breakdown section: an icon-led header, the running total, and a table
     * of grouped rows.
     */
    private function breakdownSection(
        string $heading,
        string $stateKey,
        string $icon,
        string $color,
        callable $total,
        string $labelColumn,
    ): Section {
        return Section::make($heading)
            ->icon($icon)
            ->iconColor($color)
            ->description(fn () => 'House income — '.Format::money($total()))
            ->schema([
                RepeatableEntry::make($stateKey)
                    ->hiddenLabel()
                    ->placeholder('No income recorded for this period.')
                    ->table([
                        TableColumn::make($labelColumn),
                        TableColumn::make('House Income'),
                        TableColumn::make('Games Played'),
                    ])
                    ->schema([
                        TextEntry::make('label')
                            ->badge()
                            ->color($color),
                        TextEntry::make('total_income')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                        TextEntry::make('games_played')
                            ->formatStateUsing(fn ($state): string => Format::formatNumber($state)),
                    ]),
            ]);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array{label: string, total_income: mixed}>
     */
    private function mapSingles(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'label' => ($row['players'] ?? '—').' Players',
                'total_income' => $row['total_income'] ?? 0,
                'games_played' => $row['games_played'] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array{label: string, total_income: mixed}>
     */
    private function mapRounds(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'label' => ($row['jp_rounds'] ?? $row['rounds'] ?? '—').' Rounds',
                'total_income' => $row['total_income'] ?? 0,
                'games_played' => $row['games_played'] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array{label: string, total_income: mixed}>
     */
    private function mapJackpots(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): array {
                $rounds = (int) ($row['jp_rounds'] ?? $row['rounds'] ?? 0);

                return [
                    'label' => match ($rounds) {
                        21 => 'Gold (21)',
                        17 => 'Silver (17)',
                        13 => 'Bronze (13)',
                        default => $rounds ? "{$rounds} Rounds" : '—',
                    },
                    'total_income' => $row['total_income'] ?? 0,
                    'games_played' => $row['games_played'] ?? 0,
                ];
            })
            ->values()
            ->all();
    }

    protected function onFilterApplied(): void
    {
        $this->cachedReport = null;
        $this->refresh();
    }
}
