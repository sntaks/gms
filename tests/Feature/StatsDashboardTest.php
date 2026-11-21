<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Stats\StatsByGameWidget;
use App\Filament\Widgets\Stats\StatsByProductWidget;
use App\Filament\Widgets\Stats\StatsFooterWidget;
use App\Filament\Widgets\Stats\StatsKpiWidget;
use App\Filament\Widgets\Stats\StatsRevenueChartWidget;
use App\Services\GameApiService;
use App\Support\StatsFilters;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\travel;
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
 * A `/stats/dashboard` answer for one day of slots and Kadi play.
 *
 * @param  array<string, mixed>  $data
 */
function statsDashboard(array $data = []): array
{
    return ['success' => true, 'data' => [
        'range' => ['from' => '2026-11-21', 'to' => '2026-11-21', 'timezone' => 'Africa/Nairobi'],
        'compare' => ['mode' => 'previous_period', 'from' => '2026-11-20', 'to' => '2026-11-20', 'timezone' => 'Africa/Nairobi'],
        'totals' => [
            'bets' => 120, 'voids' => 2, 'turnover' => 50000.0, 'payouts' => 40000.0, 'ggr' => 12500.0, 'hold' => 25.0, 'rtp' => 80.0,
            'promotions' => 500.0, 'net' => 12000.0, 'active_players' => 40, 'new_players' => 6, 'first_depositors' => 3,
            'deposits' => 90000.0, 'withdrawals' => 30000.0, 'net_cash' => 60000.0,
            'previous' => ['ggr' => 10000.0, 'net' => 12000.0, 'turnover' => 40000.0, 'hold' => 25.0, 'active_players' => 32, 'new_players' => 0, 'deposits' => 80000.0],
            'delta_pct' => ['ggr' => 25.0, 'net' => 0.0, 'turnover' => 25.0, 'hold' => 0.0, 'active_players' => 25.0, 'new_players' => null, 'deposits' => 12.5],
        ],
        'series' => [
            ['date' => '2026-11-20', 'ggr' => 10000.0, 'net' => 9500.0, 'turnover' => 40000.0, 'deposits' => 80000.0, 'withdrawals' => 20000.0, 'active_players' => 32],
            ['date' => '2026-11-21', 'ggr' => 12500.0, 'net' => 12000.0, 'turnover' => 50000.0, 'deposits' => 90000.0, 'withdrawals' => 30000.0, 'active_players' => 40],
        ],
        'previous_series' => [
            ['date' => '2026-11-19', 'ggr' => 9000.0, 'net' => 8000.0, 'turnover' => 30000.0, 'deposits' => 70000.0, 'withdrawals' => 10000.0, 'active_players' => 30],
            ['date' => '2026-11-20', 'ggr' => 10000.0, 'net' => 9500.0, 'turnover' => 40000.0, 'deposits' => 80000.0, 'withdrawals' => 20000.0, 'active_players' => 32],
        ],
        'by_game' => [
            ['game' => 'slots', 'products' => ['slots_fruit'], 'bets' => 100, 'players' => 30, 'turnover' => 48000.0, 'payouts' => 40000.0, 'ggr' => 8000.0, 'hold' => 16.67, 'rtp' => 83.33, 'net' => 7500.0,
                'series' => [['date' => '2026-11-20', 'ggr' => 6000.0, 'players' => 20], ['date' => '2026-11-21', 'ggr' => 8000.0, 'players' => 30]],
                'delta_pct' => ['ggr' => -12.5]],
        ],
        'by_product' => [
            ['product' => 'kadi', 'game' => 'kadi', 'revenue_model' => 'rake', 'bets' => 20, 'voids' => 0, 'players' => 10, 'turnover' => 2000.0, 'payouts' => null, 'ggr' => 4500.0, 'hold' => 225.0, 'rtp' => null,
                'avg_stake' => 100.0, 'promotions' => 0.0, 'net' => 4500.0, 'open_wagers' => 0, 'open_stakes' => 0.0, 'open_potential_payout' => 0.0],
        ],
        'players' => ['active' => ['day' => 40, 'week' => 90, 'month' => 200], 'new' => 6, 'returning' => 34, 'cross_game' => ['1' => 30, '2' => 8, '3_plus' => 2]],
        'liability' => ['open_wagers' => 4, 'open_stakes' => 800.0, 'open_potential_payout' => 5000.0, 'by_product' => [['product' => 'slots_fruit', 'open_stakes' => 800.0, 'open_potential_payout' => 5000.0]]],
        'generated_at' => '2026-11-21T07:59:00+00:00',
        'warnings' => ['2026-11-18 has no complete rollup; run php artisan stats:rebuild.'],
        'notes' => ['ggr is turnover - payouts for house-banked products and the house cut for rake products.'],
        ...$data,
    ]];
}

function fakeStatsDashboard(array $data = []): void
{
    Http::fake([
        'https://game-api.test/stats/dashboard?*' => Http::response(statsDashboard($data)),
        'https://game-api.test/games?*' => Http::response(['data' => [
            ['code' => 'slots', 'name' => 'Slots', 'products' => [['code' => 'slots_fruit', 'name' => 'Fruit Party']]],
            ['code' => 'kadi', 'name' => 'Kadi', 'products' => [['code' => 'kadi', 'name' => 'Kadi singles']]],
        ]]),
    ]);
}

function statsDashboardRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/stats/dashboard'))->count();
}

it('asks KadiApi for the chosen period, comparison and filters', function (): void {
    expect(StatsFilters::query(['period' => '7d', 'compare' => 'same_period_last_year', 'game' => 'slots', 'exclude_test' => false]))
        ->toBe(['period' => '7d', 'group_by' => 'day', 'exclude_test' => false, 'compare' => 'same_period_last_year', 'game' => 'slots'])
        ->and(StatsFilters::query(['period' => 'custom', 'from' => '2026-06-01', 'to' => '2026-11-20']))
        ->toBe(['from' => '2026-06-01', 'to' => '2026-11-20', 'group_by' => 'month', 'exclude_test' => true, 'compare' => 'previous_period'])
        ->and(StatsFilters::query(['period' => 'custom']))
        ->toMatchArray(['period' => 'today']);
});

it('keeps a statistics answer for at most a minute', function (): void {
    fakeStatsDashboard();
    $api = app(GameApiService::class);

    $api->statsReport('dashboard', ['period' => 'today'], 300);
    $api->statsReport('dashboard', ['period' => 'today'], 300);
    expect(statsDashboardRequests())->toBe(1);

    travel(61)->seconds();
    $api->statsReport('dashboard', ['period' => 'today'], 300);
    expect(statsDashboardRequests())->toBe(2);
});

it('serves every dashboard widget from one statistics call', function (): void {
    signInWithRole('finance');
    fakeStatsDashboard();
    $filters = ['period' => 'today'];

    Livewire::test(StatsKpiWidget::class, ['pageFilters' => $filters]);
    Livewire::test(StatsRevenueChartWidget::class, ['pageFilters' => $filters]);
    Livewire::test(StatsByProductWidget::class, ['pageFilters' => $filters]);
    Livewire::test(StatsFooterWidget::class, ['pageFilters' => $filters]);

    expect(statsDashboardRequests())->toBe(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stats/dashboard?')
        && $request['period'] === 'today' && $request['compare'] === 'previous_period' && (string) $request['exclude_test'] === '1');
});

it('shows each headline figure with its change, and a dash when there is nothing to compare', function (): void {
    signInWithRole('finance');
    fakeStatsDashboard();

    Livewire::test(StatsKpiWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSee('KES 12,500.00')
        ->assertSee('+25.00% vs KES 10,000.00')
        ->assertSee('25.00%')
        ->assertSee('New players')
        ->assertSee('– vs 0');
});

it('hides the cash and sign-up cards when the API sends them as null under a game filter', function (): void {
    signInWithRole('finance');
    fakeStatsDashboard(['totals' => [
        'ggr' => 8000.0, 'net' => 7500.0, 'turnover' => 48000.0, 'hold' => 16.67, 'active_players' => 30,
        'new_players' => null, 'first_depositors' => null, 'deposits' => null, 'withdrawals' => null, 'net_cash' => null,
        'previous' => null, 'delta_pct' => [],
    ]]);

    Livewire::test(StatsKpiWidget::class, ['pageFilters' => ['period' => 'today', 'game' => 'slots']])
        ->assertSee('KES 8,000.00')
        ->assertDontSee('Deposits')
        ->assertDontSee('New players')
        ->assertDontSee('Net cash');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stats/dashboard?') && $request['game'] === 'slots');
});

it('shows n/a for the payouts and RTP of a Kadi rake product', function (): void {
    signInWithRole('finance');
    fakeStatsDashboard();

    Livewire::test(StatsByProductWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSee('Kadi singles')
        ->assertSee('KES 4,500.00')
        ->assertSee('n/a');
});

it('filters the dashboard by the game a row is clicked on', function (): void {
    signInWithRole('finance');
    fakeStatsDashboard();

    Livewire::test(StatsByGameWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSee('Slots')
        ->assertSee('-12.50%')
        ->callTableAction('filterByGame', 'slots')
        ->assertDispatched('stats-filter-game', game: 'slots');

    Livewire::test(Dashboard::class)
        ->set('filters.product', 'kadi')
        ->call('filterByGame', 'slots')
        ->assertSet('filters.game', 'slots')
        ->assertSet('filters.product', null);
});

it('shows the warnings and when the figures were built, in Nairobi time', function (): void {
    signInWithRole('finance');
    fakeStatsDashboard();

    Livewire::test(StatsFooterWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSee('2026-11-18 has no complete rollup; run php artisan stats:rebuild.')
        ->assertSee('Figures as of 21 Nov 2026, 10:59');
});

it('explains a refused filter instead of showing empty figures', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/stats/dashboard?*' => Http::response([
        'message' => 'The given data was invalid.',
        'errors' => ['product' => ['The product does not belong to the game.']],
    ], 422)]);

    Livewire::test(StatsKpiWidget::class, ['pageFilters' => ['period' => 'today', 'game' => 'slots', 'product' => 'kadi']])
        ->assertSee('The filters were refused: The product does not belong to the game.');
});

it('renders the statistics section for finance with one statistics call', function (): void {
    signInWithRole('finance');
    fakeStatsDashboard();

    get('/')
        ->assertOk()
        ->assertSee('Business at a glance')
        ->assertSee('GGR, net and turnover')
        ->assertSee('By product')
        ->assertSee('Liability now');

    expect(statsDashboardRequests())->toBe(1);
});

it('keeps the statistics from managers while they still open the dashboard', function (): void {
    signInWithRole('manager');

    expect(StatsKpiWidget::canView())->toBeFalse()
        ->and(Dashboard::canViewStats())->toBeFalse();

    get('/')->assertOk()->assertDontSee('Business at a glance');
    Http::assertNothingSent();
});

it('downloads the statistics CSV for the dashboard query without exposing the key', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/stats/export/stats-games?*' => Http::response("product,ggr\nslots_fruit,8000.00\n", 200, ['Content-Disposition' => 'attachment; filename="stats-games-2026-11-21.csv"'])]);

    get(route('stats.export', ['report' => 'stats-games', 'period' => 'today', 'game' => 'slots', 'exclude_test' => 1]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="stats-games-2026-11-21.csv"')
        ->assertSee('slots_fruit,8000.00', false)
        ->assertDontSee('test-api-key');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stats/export/stats-games?')
        && $request['game'] === 'slots' && $request->header('X-API-KEY')[0] === 'test-api-key');
});

it('refuses the statistics CSV to managers and unknown reports', function (): void {
    signInWithRole('manager');
    get(route('stats.export', ['report' => 'stats-games']))->assertForbidden();

    signInWithRole('finance');
    get(route('stats.export', ['report' => 'ledger']))->assertNotFound();
});
