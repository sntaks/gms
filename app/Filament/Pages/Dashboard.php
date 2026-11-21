<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AgentStatsWidget;
use App\Filament\Widgets\BetShopsFinanceWidget;
use App\Filament\Widgets\ComplaintsStatsWidget;
use App\Filament\Widgets\ExciseDutyWidget;
use App\Filament\Widgets\FinanceOverviewWidget;
use App\Filament\Widgets\MpesaBalanceStatsWidget;
use App\Filament\Widgets\PlatformPositionWidget;
use App\Filament\Widgets\PlayerRegistrationTrendWidget;
use App\Filament\Widgets\PurchasesStatsOverviewWidget;
use App\Filament\Widgets\ReferralFinanceWidget;
use App\Filament\Widgets\ReferralProgrammeWidget;
use App\Filament\Widgets\ReferralReconciliationWidget;
use App\Filament\Widgets\ShareDistributionChartWidget;
use App\Filament\Widgets\ShareholdersTableWidget;
use App\Filament\Widgets\ShopReconciliationWidget;
use App\Filament\Widgets\StakesVersusPayoutsChartWidget;
use App\Filament\Widgets\Stats\StatsByGameWidget;
use App\Filament\Widgets\Stats\StatsByProductWidget;
use App\Filament\Widgets\Stats\StatsCashChartWidget;
use App\Filament\Widgets\Stats\StatsFooterWidget;
use App\Filament\Widgets\Stats\StatsKpiWidget;
use App\Filament\Widgets\Stats\StatsLiabilityWidget;
use App\Filament\Widgets\Stats\StatsPlayersWidget;
use App\Filament\Widgets\Stats\StatsRevenueChartWidget;
use App\Filament\Widgets\TopCustomersWidget;
use App\Filament\Widgets\TopReferrersWidget;
use App\Filament\Widgets\TopShopsByGgrWidget;
use App\Filament\Widgets\UnmatchedDepositsWidget;
use App\Filament\Widgets\WithdrawalsThisMonthWidget;
use App\Services\GameApiService;
use App\Support\StatsFilters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The GMS dashboard. On top, for admins and finance, every game and product from one KadiApi
 * `/stats/dashboard` call for the chosen period, game and product. Below, the existing widgets in
 * titled sections, each still gated as before.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    /**
     * The statistics section, in grid order, all reading the same `/stats/dashboard` answer.
     *
     * @var list<class-string>
     */
    public const STATS_WIDGETS = [
        StatsKpiWidget::class,
        StatsRevenueChartWidget::class,
        StatsCashChartWidget::class,
        StatsByGameWidget::class,
        StatsByProductWidget::class,
        StatsPlayersWidget::class,
        StatsLiabilityWidget::class,
        StatsFooterWidget::class,
    ];

    /**
     * The other dashboard widgets, grouped under a heading. A section none of whose widgets the
     * user may see is left out.
     *
     * @var array<string, list<class-string>>
     */
    public const SECTIONS = [
        'Players' => [PlayerRegistrationTrendWidget::class, AgentStatsWidget::class, TopCustomersWidget::class],
        'Income' => [FinanceOverviewWidget::class, StakesVersusPayoutsChartWidget::class, PurchasesStatsOverviewWidget::class],
        'Cash and attention' => [MpesaBalanceStatsWidget::class, PlatformPositionWidget::class, UnmatchedDepositsWidget::class, ComplaintsStatsWidget::class, ExciseDutyWidget::class],
        'Referrals' => [ReferralProgrammeWidget::class, TopReferrersWidget::class, ReferralFinanceWidget::class, ReferralReconciliationWidget::class],
        'Bet shops' => [BetShopsFinanceWidget::class, TopShopsByGgrWidget::class, ShopReconciliationWidget::class],
        'Payouts and shareholders' => [WithdrawalsThisMonthWidget::class, ShareholdersTableWidget::class, ShareDistributionChartWidget::class],
    ];

    public static function canViewStats(): bool
    {
        return auth()->user()?->canViewFinanceReports() ?? false;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Period')
                ->options(StatsFilters::PERIODS)
                ->default('today')
                ->selectablePlaceholder(false)
                ->live(),
            DatePicker::make('from')
                ->label('From')
                ->native(false)
                ->maxDate(today())
                ->visible(fn (Get $get): bool => $get('period') === 'custom'),
            DatePicker::make('to')
                ->label('Until')
                ->native(false)
                ->maxDate(today())
                ->afterOrEqual('from')
                ->visible(fn (Get $get): bool => $get('period') === 'custom'),
            Select::make('compare')
                ->label('Compared with')
                ->options(StatsFilters::COMPARES)
                ->default('previous_period')
                ->selectablePlaceholder(false),
            Select::make('game')
                ->label('Game')
                ->placeholder('All games')
                ->options(fn (): array => StatsFilters::gameOptions())
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('product', null)),
            Select::make('product')
                ->label('Product')
                ->placeholder('All products')
                ->options(fn (Get $get): array => StatsFilters::productOptions($get('game') ?: null)),
            Toggle::make('exclude_test')
                ->label('Leave out test players')
                ->default(true)
                ->inline(false),
        ]);
    }

    /**
     * Clicking a game in the "By game" table narrows the whole page to it.
     */
    #[On('stats-filter-game')]
    public function filterByGame(string $game): void
    {
        $this->filters = [...($this->filters ?? []), 'game' => $game, 'product' => null];
        $this->getFiltersForm()->fill($this->filters);
        $this->updatedFilters();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Business at a glance')
                ->description('Every game and product from KadiApi statistics, for the period and filters below.')
                ->id('stats')
                ->collapsible()
                ->persistCollapsed()
                ->visible(fn (): bool => static::canViewStats())
                ->schema([
                    $this->getFiltersFormContentComponent(),
                    Grid::make(['default' => 1, 'lg' => 2])
                        ->schema(fn (): array => $this->getWidgetsSchemaComponents(self::STATS_WIDGETS)),
                ]),
            ...collect(self::SECTIONS)
                ->map(fn (array $widgets, string $title): Component => Section::make($title)
                    ->id(Str::slug($title))
                    ->collapsible()
                    ->persistCollapsed()
                    ->visible(fn (): bool => collect($widgets)->contains(fn (string $widget): bool => $widget::canView()))
                    ->schema([
                        Grid::make($this->getColumns())
                            ->schema(fn (): array => $this->getWidgetsSchemaComponents($widgets)),
                    ]))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('definitions')
                ->label('Definitions')
                ->icon('heroicon-o-information-circle')
                ->color('gray')
                ->visible(fn (): bool => static::canViewStats())
                ->modalHeading('How these figures are defined')
                ->modalWidth(Width::TwoExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.pages.partials.stats-notes', ['notes' => $this->notes()])),
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => static::canViewStats())
                ->tooltip('Per-product figures for this period and filter (stats-games)')
                ->url(fn (): string => route('stats.export', ['report' => 'stats-games', ...StatsFilters::query($this->filters, withCompare: false)])),
        ];
    }

    /**
     * @return list<string>
     */
    protected function notes(): array
    {
        try {
            $notes = app(GameApiService::class)->statsReport('dashboard', StatsFilters::query($this->filters))['notes'] ?? [];
        } catch (\Throwable) {
            $notes = [];
        }

        return array_values(array_filter((array) $notes, 'is_string'));
    }
}
