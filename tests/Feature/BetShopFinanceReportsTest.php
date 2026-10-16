<?php

use App\Enums\UserStatus;
use App\Filament\Pages\BalanceSheetReport;
use App\Filament\Pages\BetShopDetailPage;
use App\Filament\Pages\BetShopLoadsReport;
use App\Filament\Pages\BetShopsReport;
use App\Filament\Pages\BetShopWithdrawalsReport;
use App\Filament\Pages\CashFlowReport;
use App\Filament\Pages\ExciseChargesReport;
use App\Filament\Pages\ReconciliationReport;
use App\Filament\Pages\ShopLoadsPage;
use App\Filament\Pages\ShopWithdrawalsPage;
use App\Filament\Pages\TopCustomersReport;
use App\Filament\Widgets\BetShopsFinanceWidget;
use App\Filament\Widgets\ShopReconciliationWidget;
use App\Filament\Widgets\TopShopsByGgrWidget;
use App\Livewire\BetShopReportTable;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;

/**
 * One `/finance/bet-shops` row (or the summary, which has the same money keys).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function betShopFinanceRow(array $overrides = []): array
{
    return [
        'bet_shop_id' => 3, 'shop_no' => 'S100', 'name' => 'Kariobangi', 'status' => 'active',
        'float_balance' => 6200.00, 'float_purchased' => 10000.00, 'float_redeemed' => 3000.00,
        'loads' => 1, 'loads_gross' => 1000.00, 'loads_excise' => 50.00, 'loads_net' => 950.00, 'loads_reversed' => 1,
        'cash_outs' => 1, 'cash_out_amount' => 200.00, 'withdrawals_pending' => 1, 'withdrawals_pending_amount' => 100.00,
        'game_stakes' => 180.00, 'game_stakes_open' => 30.00, 'game_winnings' => 190.00, 'game_ggr' => -40.00,
        'competition_entries' => 50.00, 'competition_prizes' => 0.00, 'competition_held' => 45.00, 'competition_ggr' => 5.00, 'ggr' => -35.00,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $summary
 * @return array<string, mixed>
 */
function betShopFinancePayload(array $items, array $summary = []): array
{
    return ['success' => true, 'data' => [
        'summary' => ['shops' => count($items), ...betShopFinanceRow(), ...$summary],
        'items' => $items,
        'pagination' => ['page' => 1, 'per_page' => 50, 'total' => count($items), 'last_page' => 1],
    ]];
}

/**
 * Fake every finance endpoint the bet shop reports and widgets read. `$stubs` go first.
 *
 * @param  array<string, mixed>  $stubs
 */
function fakeBetShopFinanceApi(array $stubs = []): void
{
    Http::preventStrayRequests();

    Http::fake($stubs + [
        '*/finance/export/*' => Http::response("\xEF\xBB\xBFbet_shop_id,shop_no\n3,S100\n", 200, ['Content-Disposition' => 'attachment; filename="finance-bet-shops-2026-10-01-2026-10-16.csv"']),
        '*/finance/bet-shops/loads*' => Http::response(['success' => true, 'data' => [
            'summary' => [
                'completed' => ['loads' => 1, 'gross_amount' => 1000.00, 'excise_amount' => 50.00, 'net_amount' => 950.00],
                'reversed' => ['loads' => 2, 'gross_amount' => 600.00, 'excise_amount' => 30.00, 'net_amount' => 570.00],
            ],
            'items' => [[
                'id' => 41, 'load_id' => '5b1c7f2e-0f1d-4a2b-9a51-6f3e2c1d0b9a', 'bet_shop_id' => 3, 'shop_no' => 'S100', 'shop_name' => 'Kariobangi',
                'customer_id' => 900, 'customer_name' => 'Alice Wanjiru', 'player_no' => '12345671', 'gross_amount' => 1000.00, 'excise_amount' => 50.00,
                'net_amount' => 950.00, 'status' => 'completed', 'loaded_by' => 'api_key:7', 'loaded_at' => '2026-10-16T09:00:00+03:00', 'reversed_at' => null, 'reversal_reason' => null,
            ]],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
        ]]),
        '*/finance/bet-shops/withdrawals*' => Http::response(['success' => true, 'data' => [
            'summary' => [
                'pending' => ['withdrawals' => 1, 'amount' => 100.00], 'paid' => ['withdrawals' => 3, 'amount' => 2600.00],
                'cancelled' => ['withdrawals' => 1, 'amount' => 50.00], 'rejected' => ['withdrawals' => 0, 'amount' => 0], 'expired' => ['withdrawals' => 1, 'amount' => 70.00],
            ],
            'items' => [[
                'id' => 18, 'withdrawal_id' => '9d2f6c1e-3b7a-4c55-8e0d-2a1f4b6c7d8e', 'bet_shop_id' => 3, 'shop_no' => 'S100', 'shop_name' => 'Kariobangi',
                'customer_id' => 900, 'customer_name' => 'Alice Wanjiru', 'player_no' => '12345671', 'amount' => 200.00, 'status' => 'paid', 'failed_attempts' => 0,
                'requested_at' => '2026-10-16T08:00:00+03:00', 'expires_at' => '2026-10-17T08:00:00+03:00', 'closed_at' => '2026-10-16T08:30:00+03:00', 'closed_by' => 'api_key:7', 'close_reason' => null,
            ]],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
        ]]),
        '*/finance/bet-shops*' => Http::response(betShopFinancePayload([betShopFinanceRow()])),
        '*/finance/balance-sheet*' => Http::response(['success' => true, 'data' => [
            'as_of' => '2026-10-16', 'source' => 'live',
            'assets' => ['cash' => ['accounts' => [], 'total' => 9000.00], 'total' => 9000.00],
            'liabilities' => ['customer_wallets' => 2000.00, 'bet_shop_floats' => 6200.00, 'shop_withdrawals' => 100.00, 'total' => 8300.00],
            'house_wallet' => 300.00, 'difference' => 400.00, 'notes' => [],
        ]]),
        '*/finance/cash-flow*' => Http::response(['success' => true, 'data' => [
            'totals' => [
                'cash_in' => ['wallet_deposit' => 500.00, 'shop_float' => 10000.00, 'total' => 10500.00],
                'cash_out' => ['paid' => 150.00, 'pending' => 0.00, 'failed' => 0.00],
                'shop_float' => ['redeemed' => 3000.00],
                'shop_cash' => ['loaded' => 1000.00, 'paid_out' => 200.00],
                'net_cash' => 7350.00,
            ],
            'series' => [[
                'period' => '2026-10-16', 'cash_in' => ['total' => 10500.00], 'cash_out' => ['paid' => 150.00, 'pending' => 0, 'failed' => 0],
                'shop_float' => ['redeemed' => 3000.00], 'shop_cash' => ['loaded' => 1000.00, 'paid_out' => 200.00], 'net_cash' => 7350.00,
            ]],
        ]]),
        '*/finance/customers/top*' => Http::response(['success' => true, 'data' => [
            'summary' => ['customer_wallets_total' => 2580.00, 'concentration' => ['top_wallets' => 10, 'balance' => 2580.00, 'share_percent' => 100.0]],
            'items' => [['customer_id' => 900, 'customer_name' => 'Alice Wanjiru', 'deposited' => 1500.00, 'shop_loaded' => 1000.00, 'withdrawn' => 700.00, 'shop_withdrawn' => 200.00, 'staked' => 100.00, 'won' => 0.00, 'net_gaming' => -100.00, 'balance' => 1400.00]],
        ]]),
        '*/finance/excise-duty/charges*' => Http::response(['success' => true, 'data' => [
            'summary' => ['charges' => 1, 'gross_deposits' => 1000.00, 'excise' => 50.00, 'by_status' => [], 'unremitted' => ['charges' => 1, 'excise' => 50.00]],
            'items' => [['id' => 12, 'charged_at' => '2026-10-16T09:00:00+03:00', 'source' => 'shop_load', 'bet_shop_load_id' => 41, 'deposit_id' => null, 'trans_id' => null, 'customer_id' => 900, 'customer_name' => 'Alice Wanjiru', 'msisdn' => null, 'gross_amount' => 1000.00, 'rate' => 0.05, 'excise_amount' => 50.00, 'net_amount' => 950.00, 'status' => 'charged', 'remittance_id' => null, 'kra_reference' => null]],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
        ]]),
        '*/finance/reconciliation*' => Http::response(['success' => true, 'data' => [
            'status' => 'warn',
            'counts' => ['pass' => 1, 'warn' => 1, 'fail' => 0],
            'checks' => [
                ['key' => 'stale_shop_withdrawals', 'title' => 'Shop withdrawals past expiry', 'status' => 'warn', 'count' => 2, 'amount' => 300.00, 'detail' => 'The expire job is not running.'],
                ['key' => 'bet_shop_float_drift', 'title' => 'Bet shop floats match the ledger', 'status' => 'pass', 'count' => 0, 'amount' => 0, 'detail' => null],
                ['key' => 'customer_wallet_drift', 'title' => 'Customer wallets match the ledger', 'status' => 'pass', 'count' => 0, 'amount' => 0, 'detail' => null],
            ],
        ]]),
        '*/bet-shops*' => Http::response(['success' => true, 'data' => [
            'items' => [['id' => 3, 'shop_no' => 'S100', 'name' => 'Kariobangi', 'status' => 'active']],
            'pagination' => ['page' => 1, 'per_page' => 200, 'total' => 1, 'last_page' => 1],
        ]]),
    ]);
}

/**
 * Whether a table cell shows `$amount` in red (Filament's danger colour).
 */
function showsInRed(string $html, string $amount): bool
{
    return preg_match('/fi-ta-text-item[^"]*fi-color-danger[^"]*"[^>]*>\s*(<span[^>]*>\s*)?'.preg_quote($amount, '/').'/', $html) === 1;
}

/**
 * The query string of every request to one finance path.
 *
 * @return list<array<string, string>>
 */
function betShopFinanceQueries(string $path): array
{
    return Http::recorded(fn (Request $request): bool => str_contains((string) parse_url($request->url(), PHP_URL_PATH), $path))
        ->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        })
        ->values()
        ->all();
}

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo('2026-10-16 10:00:00');

    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);

    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    $this->admin = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->admin->assignRole('admin');

    // Managers hold reports.view but are not admins, so they are not finance.
    $this->manager = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->manager->assignRole('manager');
});

it('shows each shop\'s float, cash and GGR, with negative GGR in red', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopsReport::class)
        ->assertSee('S100')
        ->assertSee('KES 6,200.00')
        ->assertSee('KES 1,000.00')
        ->assertSee('KES -35.00')
        ->assertSee(BetShopsReport::GGR_TOOLTIP)
        ->assertSeeHtml(e(BetShopDetailPage::getUrl(['shop' => 3])))
        ->tap(fn ($page) => expect(showsInRed($page->html(), 'KES -35.00'))->toBeTrue()
            ->and(showsInRed($page->html(), 'KES 6,200.00'))->toBeFalse());
});

it('does not colour a positive GGR red', function (): void {
    fakeBetShopFinanceApi(['*/finance/bet-shops*' => Http::response(betShopFinancePayload(
        [betShopFinanceRow(['ggr' => 120.00, 'game_ggr' => 100.00, 'competition_ggr' => 20.00])],
        ['ggr' => 120.00, 'game_ggr' => 100.00, 'competition_ggr' => 20.00],
    ))]);
    $this->actingAs($this->admin);

    Livewire::test(BetShopsReport::class)
        ->assertSee('KES 120.00')
        ->tap(fn ($page) => expect(showsInRed($page->html(), 'KES 120.00'))->toBeFalse());
});

it('passes the shop status filter to KadiApi', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopsReport::class)->filterTable('status', 'suspended');

    expect(collect(betShopFinanceQueries('/finance/bet-shops'))->pluck('status')->filter()->values()->all())->toBe(['suspended']);
});

it('totals completed and reversed loads and filters them by shop', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopLoadsReport::class)
        ->assertSee('Alice Wanjiru')
        ->assertSee('1234 5671')
        ->assertSee('Kariobangi')
        ->assertSee('2 loads reversed by Kadi Kings')
        ->assertSeeHtml(e(ShopLoadsPage::getUrl(['load' => 41])))
        ->filterTable('bet_shop_id', 3);

    expect(collect(betShopFinanceQueries('/finance/bet-shops/loads'))->pluck('bet_shop_id')->filter()->values()->all())->toBe(['3']);
});

it('counts shop withdrawals by status', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopWithdrawalsReport::class)
        ->assertSee('KES 2,600.00')
        ->assertSee('KES 120.00')
        ->assertSee('Alice Wanjiru')
        ->assertSee('Expired');
});

it('keeps the bet shop finance reports from users who are not finance', function (string $page): void {
    $this->actingAs($this->manager);

    get($page::getUrl())->assertForbidden();
})->with([BetShopsReport::class, BetShopLoadsReport::class, BetShopWithdrawalsReport::class]);

it('streams the bet shop exports through the GMS without exposing the API key', function (string $report): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    $response = get(route('finance.export', ['report' => $report, 'from' => '2026-10-01', 'to' => '2026-10-16', 'bet_shop_id' => 3]));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="finance-bet-shops-2026-10-01-2026-10-16.csv"');
    expect($response->getContent())->not->toContain('test-api-key');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/finance/export/{$report}?")
        && str_contains($request->url(), 'bet_shop_id=3')
        && $request->header('X-API-KEY')[0] === 'test-api-key');
})->with(['bet-shops', 'bet-shop-loads', 'bet-shop-withdrawals']);

it('refuses the bet shop exports to users who are not finance', function (): void {
    $this->actingAs($this->manager);

    get(route('finance.export', ['report' => 'bet-shops']))->assertForbidden();
});

it('adds the bet shop liabilities to the balance sheet', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(BalanceSheetReport::class)
        ->assertSee('Bet shop floats')
        ->assertSee('KES 6,200.00')
        ->assertSee('Shop withdrawals waiting to be collected')
        ->assertSee('A snapshot from before bet shops existed shows 0 for both.');
});

it('shows float bought, float paid back and counter cash apart in the cash flow', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(CashFlowReport::class)
        ->assertSee('Shop float bought')
        ->assertSee('KES 10,000.00')
        ->assertSee('Shop float paid back')
        ->assertSee('KES 3,000.00')
        ->assertSee(CashFlowReport::SHOP_CASH_NOTE)
        ->assertSee('Shop cash out');
});

it('shows shop cash in the top customers and ranks by it', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(TopCustomersReport::class)
        ->assertSee('Shop loaded')
        ->assertSee('KES 1,000.00')
        ->callAction('sortBy', data: ['sort' => 'shop_loaded']);

    expect(collect(betShopFinanceQueries('/finance/customers/top'))->pluck('sort')->all())->toContain('shop_loaded');
});

it('links a shop load excise charge to its load', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(ExciseChargesReport::class)
        ->assertSee('Shop load')
        ->assertSee('Load #41')
        ->assertSeeHtml(e(ShopLoadsPage::getUrl(['load' => 41])));
});

it('links stale shop withdrawals from the reconciliation to the pending list', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(ReconciliationReport::class)
        ->assertSee('Shop withdrawals past expiry')
        ->assertActionVisible('staleShopWithdrawals')
        ->assertActionHasUrl('staleShopWithdrawals', ShopWithdrawalsPage::getUrlForStatus('pending'));

    Livewire::test(ShopReconciliationWidget::class)
        ->assertSee('Shop withdrawals past expiry')
        ->assertSee('Bet shop floats match the ledger')
        ->assertDontSee('Customer wallets match the ledger')
        ->assertSeeHtml(e(ShopWithdrawalsPage::getUrlForStatus('pending')));
});

it('hides the stale shop withdrawals link when the check passes', function (): void {
    fakeBetShopFinanceApi(['*/finance/reconciliation*' => Http::response(['success' => true, 'data' => [
        'status' => 'pass', 'counts' => ['pass' => 1, 'warn' => 0, 'fail' => 0],
        'checks' => [['key' => 'stale_shop_withdrawals', 'title' => 'Shop withdrawals past expiry', 'status' => 'pass', 'count' => 0, 'amount' => 0, 'detail' => null]],
    ]])]);
    $this->actingAs($this->admin);

    Livewire::test(ReconciliationReport::class)->assertActionHidden('staleShopWithdrawals');
});

it('shows the float held, today\'s loads and pending withdrawals on the dashboard from one cached call', function (): void {
    fakeBetShopFinanceApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopsFinanceWidget::class)
        ->assertSee('KES 6,200.00')
        ->assertSee('KES 1,000.00')
        ->assertSee('KES 100.00 waiting to be collected');
    Livewire::test(BetShopsFinanceWidget::class);

    expect(betShopFinanceQueries('/finance/bet-shops'))->toBe([['from' => '2026-10-16', 'per_page' => '200', 'to' => '2026-10-16']]);
});

it('stops calling KadiApi until Retry-After once the GMS key is rate limited', function (): void {
    fakeBetShopFinanceApi(['*/finance/bet-shops*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30'])]);
    $this->actingAs($this->admin);

    Livewire::test(BetShopsFinanceWidget::class)->assertSee('KadiApi rate limit reached; retrying in about 30s');
    Livewire::test(TopShopsByGgrWidget::class)->assertSee('KadiApi rate limit reached');

    expect(betShopFinanceQueries('/finance/bet-shops'))->toHaveCount(1);
});

it('ranks the top shops by GGR this month, negative GGR in red', function (): void {
    fakeBetShopFinanceApi(['*/finance/bet-shops*' => Http::response(betShopFinancePayload([
        betShopFinanceRow(['bet_shop_id' => 3, 'shop_no' => 'S100', 'ggr' => -35.00]),
        betShopFinanceRow(['bet_shop_id' => 4, 'shop_no' => 'S200', 'ggr' => 900.00]),
    ]))]);
    $this->actingAs($this->admin);

    Livewire::test(TopShopsByGgrWidget::class)
        ->assertSeeInOrder(['S200', 'S100'])
        ->tap(fn ($widget) => expect(showsInRed($widget->html(), 'KES -35.00'))->toBeTrue()
            ->and(showsInRed($widget->html(), 'KES 900.00'))->toBeFalse());

    expect(betShopFinanceQueries('/finance/bet-shops'))->toBe([['from' => '2026-10-01', 'per_page' => '200', 'to' => '2026-10-16']]);
});

it('shows the bet shop dashboard widgets to finance only', function (string $widget): void {
    $this->actingAs($this->admin);
    expect($widget::canView())->toBeTrue();

    $this->actingAs($this->manager);
    expect($widget::canView())->toBeFalse();
})->with([BetShopsFinanceWidget::class, TopShopsByGgrWidget::class, ShopReconciliationWidget::class]);

it('shows one shop\'s figures for the chosen period on its Report tab', function (): void {
    fakeBetShopFinanceApi(['*/finance/bet-shops*' => Http::response(betShopFinancePayload([betShopFinanceRow()]))]);
    $this->actingAs($this->admin);

    Livewire::test(BetShopReportTable::class, ['shopId' => 3])
        ->assertSee('Float now')
        ->assertSee('KES 6,200.00')
        ->assertSee('KES -35.00')
        ->tap(fn ($table) => expect(showsInRed($table->html(), 'KES -35.00'))->toBeTrue())
        ->filterTable('period', 'today');

    expect(betShopFinanceQueries('/finance/bet-shops'))->toBe([
        ['bet_shop_id' => '3', 'from' => '2026-10-01', 'to' => '2026-10-16'],
        ['bet_shop_id' => '3', 'from' => '2026-10-16', 'to' => '2026-10-16'],
    ]);
});

it('keeps the shop Report tab from users who are not finance', function (): void {
    $this->actingAs($this->manager);

    Livewire::test(BetShopReportTable::class, ['shopId' => 3])->assertForbidden();
});
