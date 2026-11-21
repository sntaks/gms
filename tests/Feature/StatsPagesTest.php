<?php

use App\Filament\Pages\CustomerStatementReport;
use App\Filament\Pages\Statistics\StatsGamesPage;
use App\Filament\Pages\Statistics\StatsLiabilityPage;
use App\Filament\Pages\Statistics\StatsPaymentsPage;
use App\Filament\Pages\Statistics\StatsPlayersPage;
use App\Filament\Pages\Statistics\StatsPromotionsPage;
use App\Filament\Pages\Statistics\StatsTopPlayersPage;
use App\Filament\Widgets\Stats\StatsChannelsWidget;
use App\Filament\Widgets\Stats\StatsCohortWidget;
use App\Filament\Widgets\Stats\StatsHeatmapWidget;
use App\Filament\Widgets\Stats\StatsLiabilityProductsWidget;
use App\Filament\Widgets\Stats\StatsPaymentsOverviewWidget;
use App\Filament\Widgets\Stats\StatsPlayersOverviewWidget;
use App\Filament\Widgets\Stats\StatsProductSeriesChartWidget;
use App\Filament\Widgets\Stats\StatsPromotionTypesWidget;
use App\Filament\Widgets\Stats\StatsTopPlayersWidget;
use App\Filament\Widgets\Stats\StatsTopPositionsWidget;
use App\Filament\Widgets\Stats\StatsWithdrawalFailuresChartWidget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);
    Cache::flush();
    Http::preventStrayRequests();
    travelTo('2026-11-21 10:00:00');
});

/**
 * @param  array<string, mixed>  $data
 */
function statsAnswer(array $data): array
{
    return ['success' => true, 'data' => ['generated_at' => '2026-11-21T07:00:00+00:00', 'warnings' => [], 'notes' => ['Days are Nairobi days.'], ...$data]];
}

function fakeStatsCatalogue(): array
{
    return ['https://game-api.test/games?*' => Http::response(['data' => [
        ['code' => 'slots', 'name' => 'Slots', 'products' => [['code' => 'slots_fruit', 'name' => 'Fruit Party']]],
        ['code' => 'predict', 'name' => 'Predictions', 'products' => [['code' => 'predict_pool', 'name' => 'Prediction pools']]],
    ]])];
}

it('keeps the statistics pages to finance, and opens Liability to the trading roles', function (string $page, bool $managerMay): void {
    signInWithRole('finance');
    expect($page::canAccess())->toBeTrue();

    signInWithRole('manager');
    expect($page::canAccess())->toBe($managerMay);

    signInWithRole('agent');
    expect($page::canAccess())->toBeFalse();
})->with([
    [StatsGamesPage::class, false],
    [StatsLiabilityPage::class, true],
    [StatsPlayersPage::class, false],
    [StatsTopPlayersPage::class, false],
    [StatsPromotionsPage::class, false],
    [StatsPaymentsPage::class, false],
]);

it('opens each statistics page with the filters its answer takes', function (string $page, string $filter, string $absent): void {
    signInWithRole('finance');
    Http::fake([...fakeStatsCatalogue(), 'https://game-api.test/stats/*' => Http::response(statsAnswer([]))]);

    get($page::getUrl())
        ->assertOk()
        ->assertSee('Leave out test players')
        ->assertSee($filter)
        ->assertDontSee($absent)
        ->assertSee('Definitions');
})->with([
    [StatsGamesPage::class, 'All products', 'Compared with'],
    [StatsLiabilityPage::class, 'All games', 'This month'],
    [StatsPlayersPage::class, 'Period', 'Rank by'],
    [StatsTopPlayersPage::class, 'Rank by', 'Compared with'],
    [StatsPromotionsPage::class, 'Period', 'Compared with'],
    [StatsPaymentsPage::class, 'Period', 'All games'],
]);

it('refuses a statistics page to a manager', function (): void {
    signInWithRole('manager');

    get(StatsPlayersPage::getUrl())->assertForbidden();
});

it('draws a product chart and the weekday-by-hour heatmap from the games answer', function (): void {
    signInWithRole('finance');
    Http::fake([...fakeStatsCatalogue(), 'https://game-api.test/stats/games?*' => Http::response(statsAnswer([
        'by_product' => [['product' => 'slots_fruit', 'game' => 'slots', 'series' => [
            ['date' => '2026-11-20', 'turnover' => 1000.0, 'ggr' => 100.0, 'rtp' => 90.0],
            ['date' => '2026-11-21', 'turnover' => 2000.0, 'ggr' => 300.0, 'rtp' => 85.0],
        ]]],
        'hourly_heatmap' => [['weekday' => 'fri', 'hour' => 20, 'stakes' => 15000.0, 'players' => 4, 'avg_players' => 2.0]],
    ]))]);
    $filters = ['period' => '7d', 'compare' => 'same_period_last_year'];

    Livewire::test(StatsProductSeriesChartWidget::class, ['product' => 'slots_fruit', 'pageFilters' => $filters])
        ->assertSee('Fruit Party')
        ->assertSee('Slots · turnover and GGR in KES, RTP in %');

    Livewire::test(StatsHeatmapWidget::class, ['pageFilters' => $filters])
        ->assertSee('Fri 20:00 · KES 15.00K')
        ->call('showMetric', 'avg_players')
        ->assertSee('Fri 20:00 · 2.00');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stats/games?')
        && $request['period'] === '7d' && ! isset($request['compare']));
});

it('shows how complete a reported potential payout is, and links a position to its market', function (): void {
    signInWithRole('manager');
    Http::fake([...fakeStatsCatalogue(), 'https://game-api.test/stats/liability?*' => Http::response(statsAnswer([
        'open_wagers' => 5, 'open_stakes' => 2500.0, 'open_potential_payout' => 40000.0,
        'by_product' => [
            ['product' => 'slots_fruit', 'game' => 'slots', 'open_wagers' => 3, 'open_stakes' => 500.0, 'open_free_bet_value' => 0.0, 'open_potential_payout' => 2000.0, 'potential_payout_basis' => 'reported', 'wagers_with_potential_payout' => 1],
            ['product' => 'predict_pool', 'game' => 'predict', 'open_wagers' => 2, 'open_stakes' => 2000.0, 'open_free_bet_value' => 0.0, 'open_potential_payout' => 38000.0, 'potential_payout_basis' => 'worst_side', 'wagers_with_potential_payout' => 2],
        ],
        'top_positions' => [['reference' => 'pr-77', 'product' => 'predict_pool', 'stake' => 2000.0, 'free_bet_amount' => 0.0, 'potential_payout' => 38000.0, 'predict_market_id' => 41, 'side' => 'yes', 'placed_at' => '2026-11-21T06:30:00+00:00']],
    ]))]);

    Livewire::test(StatsLiabilityProductsWidget::class, ['pageFilters' => ['period' => '30d']])
        ->assertSee('Reported by the game')
        ->assertSee('1 of 3 wagers report one')
        ->assertSee('Worst side')
        ->assertSee('KES 38.00K');

    Livewire::test(StatsTopPositionsWidget::class, ['pageFilters' => []])
        ->assertSee('Market #41 · Yes')
        ->assertSee('21 Nov 2026, 09:30');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stats/liability?') && ! isset($request['period']));
});

it('shows conversion as converted of eligible, "not yet" with nobody eligible, and empty cohort weeks', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/stats/players?*' => Http::response(statsAnswer([
        'registrations' => ['total' => 12, 'series' => []],
        'first_deposit_conversion' => [
            'registered' => 12,
            'within_1_days' => ['eligible' => 10, 'converted' => 3, 'rate' => 30.0],
            'within_30_days' => ['eligible' => 0, 'converted' => 0, 'rate' => null],
        ],
        'active' => ['daily' => [], 'weekly' => [], 'monthly' => []],
        'retention_cohorts' => [['week' => '2026-11-09', 'registered' => 8, 'weeks' => ['week_1' => 25.0, 'week_2' => null]]],
        'churn' => ['previous_active' => 40, 'churned' => 10, 'rate' => 25.0, 'previous' => ['from' => '2026-09-23', 'to' => '2026-10-22'], 'current' => ['from' => '2026-10-23', 'to' => '2026-11-21']],
        'stake_bands' => [],
        'channel' => [['channel' => 'web', 'entries' => 1500, 'stakes' => 42000.0, 'player_days' => 90]],
    ]))]);

    Livewire::test(StatsPlayersOverviewWidget::class, ['pageFilters' => ['period' => '30d']])
        ->assertSee('3 / 10')
        ->assertSee('30.00% converted')
        ->assertSee('Not yet')
        ->assertSee('25.00%')
        ->assertSee('10 of 40 players active');

    Livewire::test(StatsCohortWidget::class, ['pageFilters' => ['period' => '30d']])
        ->assertSee('09 Nov 2026')
        ->assertSee('25.00%')
        ->assertDontSee('n/a');

    Livewire::test(StatsChannelsWidget::class, ['pageFilters' => ['period' => '30d']])
        ->assertSee('Player-days')
        ->assertSee('1.50K')
        ->assertSee('KES 42.00K');
});

it('ranks top players by the chosen figure and opens a player\'s statement', function (): void {
    signInWithRole('finance');
    Http::fake([...fakeStatsCatalogue(), 'https://game-api.test/stats/players/top?*' => Http::response(statsAnswer([
        'sort' => 'deposits',
        'players' => [['customer_id' => 5501, 'player_no' => 'P-5501', 'display_name' => 'Minks', 'turnover' => 25000.0, 'payouts' => 20000.0, 'net_loss' => 5000.0, 'ggr' => 5000.0, 'wins' => 12, 'deposits' => 30000.0, 'withdrawals' => 1000.0, 'games_played' => 3, 'last_active' => '2026-11-20']],
    ]))]);

    Livewire::test(StatsTopPlayersWidget::class, ['pageFilters' => ['period' => '30d', 'sort' => 'deposits', 'limit' => 50]])
        ->assertSee('Top players by Deposits')
        ->assertSee('Minks')
        ->assertSee('KES 30.00K')
        ->assertSee('Kadi play adds nothing to a player\'s GGR')
        ->assertSee(CustomerStatementReport::getUrl(['customerId' => 5501]), false);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stats/players/top?')
        && $request['sort'] === 'deposits' && (int) $request['limit'] === 50);
});

it('marks promotion types that are not a cost', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/stats/promotions?*' => Http::response(statsAnswer([
        'by_type' => [
            ['type' => 'signup_bonus', 'scope' => 'platform', 'is_cost' => true, 'amount' => 3000.0, 'items' => 30, 'players' => 30],
            ['type' => 'free_bet_value', 'scope' => 'product', 'is_cost' => false, 'amount' => 5000.0, 'items' => 50, 'players' => 20],
        ],
    ]))]);

    Livewire::test(StatsPromotionTypesWidget::class, ['pageFilters' => ['period' => '30d']])
        ->assertSee('Signup Bonus')
        ->assertSee('Platform')
        ->assertSee('Free Bet Value')
        ->assertSee('Information, not a cost');
});

it('asks for payments without a game or product and shows what is waiting now', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/stats/payments?*' => Http::response(statsAnswer([
        'withdrawals' => ['failed' => ['count' => 3, 'amount' => 900.0, 'by_reason' => ['insufficient_funds' => ['count' => 2, 'amount' => 600.0], 'timeout' => ['count' => 1, 'amount' => 300.0]]]],
        'held_withdrawals' => ['threshold' => 20000.0, 'requested' => ['count' => 2, 'amount' => 50000.0], 'decided' => ['approved' => ['count' => 1, 'amount' => 25000.0]], 'avg_wait_seconds' => 5400, 'awaiting_now' => ['count' => 1, 'amount' => 25000.0, 'oldest_since' => '2026-11-21T05:00:00+00:00']],
    ]))]);
    $filters = ['period' => '7d', 'game' => 'slots', 'product' => 'slots_fruit'];

    Livewire::test(StatsPaymentsOverviewWidget::class, ['group' => 'held', 'pageFilters' => $filters])
        ->assertSee('KES 50.00K · requests of KES 20.00K or more')
        ->assertSee('1 approved')
        ->assertSee('1h 30m')
        ->assertSee('KES 25.00K · oldest since 21 Nov 2026, 08:00');

    Livewire::test(StatsWithdrawalFailuresChartWidget::class, ['pageFilters' => $filters])
        ->assertSee('Grouped from the M-Pesa failure text.');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stats/payments?')
        && ! isset($request['game']) && ! isset($request['product']) && ! isset($request['compare']));
});

it('exports the top players CSV with the page\'s sort and limit', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/stats/export/stats-players-top?*' => Http::response("customer_id,turnover\n5501,25000.00\n")]);

    Livewire::withQueryParams(['filters' => ['period' => '30d', 'sort' => 'wins', 'limit' => 100]])
        ->test(StatsTopPlayersPage::class)
        ->assertActionHasUrl('exportCsv', route('stats.export', ['report' => 'stats-players-top', 'period' => '30d', 'group_by' => 'day', 'exclude_test' => true, 'sort' => 'wins', 'limit' => 100]));
});
