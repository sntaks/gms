<?php

use App\Enums\UserStatus;
use App\Filament\Pages\AdjustmentsReport;
use App\Filament\Pages\ApiExpensesReport;
use App\Filament\Pages\BalanceSheetReport;
use App\Filament\Pages\CashFlowReport;
use App\Filament\Pages\CompetitionsReport;
use App\Filament\Pages\ComplaintDetailPage;
use App\Filament\Pages\CustomerStatementReport;
use App\Filament\Pages\DepositsPage;
use App\Filament\Pages\DisputesReport;
use App\Filament\Pages\ExciseChargesReport;
use App\Filament\Pages\ExciseDutyReport;
use App\Filament\Pages\ExciseRemittancesReport;
use App\Filament\Pages\ExciseReturnsReport;
use App\Filament\Pages\GamesReport;
use App\Filament\Pages\IncomeStatementReport;
use App\Filament\Pages\LedgerReport;
use App\Filament\Pages\OverallLeaderboard;
use App\Filament\Pages\PlayerWithdrawalsPage;
use App\Filament\Pages\PurchasesPage;
use App\Filament\Pages\ReconciliationReport;
use App\Filament\Pages\TaxesReport;
use App\Filament\Pages\TopCustomersReport;
use App\Filament\Pages\TrialBalanceReport;
use App\Filament\Widgets\ExciseDutyWidget;
use App\Filament\Widgets\FinanceOverviewWidget;
use App\Filament\Widgets\PlatformPositionWidget;
use App\Filament\Widgets\PlayerEngagementWidget;
use App\Filament\Widgets\StakesVersusPayoutsChartWidget;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TopCustomersWidget;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\get;

/**
 * @return array<string, mixed>
 */
function financeFixture(): array
{
    $window = fn (float $revenue): array => [
        'from' => '2026-09-01', 'cash_in' => 1000, 'cash_out' => 200, 'net_cash' => 800,
        'revenue' => ['games' => $revenue, 'tournaments' => 0, 'jackpots' => 0, 'gift_emoji_sales' => 0, 'total' => $revenue],
        'expenses' => 10, 'net_income' => $revenue - 10, 'stakes' => 5000, 'payouts' => 4000, 'refunds' => 0, 'adjustments' => 0,
    ];

    $meta = ['period' => ['from' => '2026-09-01', 'to' => '2026-09-21', 'group_by' => 'day'], 'warnings' => ['Ledger warning shown to admins.']];
    $pagination = ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1];

    $liabilities = ['customer_wallets' => 900, 'game_escrow' => 50, 'competition_escrow' => 25, 'stuck_escrow' => 7, 'unmatched_deposits' => 122510, 'excise_duty_payable' => 25, 'disputed_funds' => 40, 'total' => 1500];
    $cash = ['accounts' => [['type' => 'b2c', 'account' => 'Utility Account', 'amount' => 28772, 'as_of' => '2026-09-05T11:57:40+03:00']], 'total' => 28772];

    return [
        'summary' => ['windows' => ['today' => $window(100), 'week' => $window(200), 'month' => $window(300), 'year' => $window(300), 'all_time' => $window(300)],
            'position' => ['assets' => ['cash' => $cash, 'total' => 28772], 'liabilities' => $liabilities, 'house_wallet' => 500, 'difference' => -1]],
        'income-statement' => ['meta' => $meta, 'revenue' => ['games' => 10, 'tournaments' => ['total' => 5, 'by_rounds' => ['3' => 5]], 'jackpots' => ['total' => 0, 'by_rounds' => []],
            'competitions_unattributed' => 1, 'gift_emoji_sales' => ['total' => 0], 'other' => 0, 'total' => 16, 'games_by_source' => ['game_credit' => 10]],
            'expenses' => ['tracked' => true, 'total' => 2, 'by_category' => []], 'net_income' => 14, 'series' => [['period' => '2026-09-01', 'games' => 10, 'tournaments' => 5, 'jackpots' => 0, 'total' => 15, 'expenses' => 1, 'net_income' => 14]]],
        'excise-duty' => ['meta' => $meta, 'enabled' => true, 'rate' => 0.05, 'effective_from' => '2026-09-01',
            'totals' => ['deposits' => 2, 'gross_deposits' => 300, 'excise_charged' => 15, 'excise_reversed' => 0, 'excise_net' => 15, 'excise_remitted' => 0],
            'payable' => ['outstanding' => 18, 'oldest_unremitted_at' => '2026-08-12T10:00:00+03:00'], 'series' => [], 'notes' => []],
        'excise-duty/returns' => ['meta' => $meta, 'filing_day' => 20, 'payable' => 18, 'items' => [
            ['period' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-09-20', 'charges' => 1, 'gross_deposits' => 60, 'excise_charged' => 3, 'excise_reversed' => 0, 'excise_due' => 3, 'excise_remitted' => 0, 'outstanding' => 3, 'overdue' => true],
            ['period' => '2026-07', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'due_date' => '2026-08-20', 'charges' => 2, 'gross_deposits' => 300, 'excise_charged' => 15, 'excise_reversed' => 0, 'excise_due' => 15, 'excise_remitted' => 15, 'outstanding' => 0, 'overdue' => false],
        ]],
        'excise-duty/charges' => ['meta' => $meta, 'summary' => ['charges' => 1, 'gross_deposits' => 100, 'excise' => 5,
            'by_status' => ['charged' => ['charges' => 1, 'gross_deposits' => 100, 'excise' => 5]], 'unremitted' => ['charges' => 1, 'excise' => 5]],
            'items' => [['id' => 1, 'charged_at' => '2026-09-19T10:00:00+03:00', 'deposit_id' => 9, 'trans_id' => 'QWE123XYZ', 'customer_id' => 5, 'customer_name' => 'Achieng', 'msisdn' => '2547****5678',
                'gross_amount' => 100, 'rate' => 0.05, 'excise_amount' => 5, 'net_amount' => 95, 'status' => 'charged', 'remittance_id' => null, 'kra_reference' => null]], 'pagination' => $pagination],
        'excise-duty/remittances' => ['meta' => $meta, 'summary' => ['active' => ['remittances' => 1, 'amount_due' => 15, 'amount_paid' => 15], 'voided' => ['remittances' => 0, 'amount_due' => 0, 'amount_paid' => 0], 'payable' => 18],
            'items' => [['id' => 7, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'charges' => 2, 'amount_due' => 15, 'amount_paid' => 15, 'difference' => 0,
                'kra_reference' => 'KRA-REF-001', 'paid_at' => '2026-08-15', 'status' => 'active', 'recorded_by' => 'api_key:4', 'created_at' => '2026-08-15T10:00:00+03:00']], 'pagination' => $pagination],
        'disputes' => ['meta' => $meta, 'summary' => ['by_status' => [
            'pending_dispute' => ['complaints' => 1, 'disputed' => 190, 'held' => 150, 'shortfall' => 40, 'refunded' => 0, 'house_cuts_reversed' => 0, 'released' => 0],
            'resolved' => ['complaints' => 1, 'disputed' => 180, 'held' => 180, 'shortfall' => 0, 'refunded' => 200, 'house_cuts_reversed' => 20, 'released' => 0],
        ], 'currently_held' => 150],
            'items' => [['id' => 3, 'complaint_id' => '9b1c2f0e-6a57-4f7e-9d0a-1f3c5b8e2a41', 'filed_at' => '2026-09-19T14:05:00+03:00', 'customer_id' => 42, 'subject_type' => 'game', 'game_wallet_id' => 1051,
                'reason' => 'Unfair result', 'status' => 'pending_dispute', 'disputed_amount' => 190, 'held_amount' => 150, 'shortfall_amount' => 40, 'still_held' => 150, 'refunded_amount' => 0, 'house_cuts_reversed' => 0, 'released_amount' => 0]], 'pagination' => $pagination],
        'cash-flow' => ['meta' => $meta, 'totals' => ['cash_in' => ['unmatched' => 100, 'total' => 100], 'cash_out' => ['paid' => 0, 'pending' => 0, 'failed' => 0], 'net_cash' => 100], 'series' => []],
        'balance-sheet' => ['as_of' => '2026-09-21', 'source' => 'live', 'assets' => ['cash' => $cash, 'total' => 28772], 'liabilities' => $liabilities, 'house_wallet' => 500, 'difference' => -1, 'notes' => ['A note']],
        'trial-balance' => ['meta' => $meta, 'lines' => [['account' => 'game_escrow', 'entry_type' => 'game_bet', 'category' => 'stake', 'debit' => 0, 'credit' => 10, 'entries' => 1]],
            'totals' => ['debit' => 5, 'credit' => 10], 'check' => ['balanced' => false, 'imbalance' => 5, 'imbalance_by_category' => ['stake' => -5]]],
        'reconciliation' => ['meta' => $meta, 'status' => 'fail', 'counts' => ['pass' => 9, 'warn' => 4, 'fail' => 3],
            'checks' => [['key' => 'drift', 'title' => 'Wallets match ledger', 'status' => 'fail', 'count' => 33, 'amount' => 494817, 'detail' => 'Balance differs']]],
        'taxes' => ['meta' => $meta, 'configured' => false, 'net_income_before_tax' => 100, 'taxes' => ['income_tax' => ['label' => 'Income tax', 'base' => 'net_income', 'kind' => 'expense', 'rate' => 0, 'base_amount' => 100, 'estimated_amount' => 0]],
            'totals' => ['expense_taxes' => 0, 'pass_through_taxes' => 0, 'net_income_after_tax' => 100]],
        'deposits' => ['meta' => $meta, 'summary' => ['payments' => 1, 'amount' => 500, 'by_kind' => ['unmatched' => ['payments' => 1, 'amount' => 500]], 'by_status' => ['unmatched' => ['payments' => 1, 'amount' => 500]], 'top_depositors' => []],
            'items' => [['id' => 7, 'trans_id' => 'UIKEQ7VTP4', 'amount' => 500, 'kind' => 'unmatched', 'status' => 'unmatched', 'customer_name' => null, 'bill_ref_no' => '0790**7280', 'created_at' => '2026-09-20T22:04:22+03:00']], 'pagination' => $pagination],
        'withdrawals' => ['meta' => $meta, 'summary' => ['payments' => 1, 'amount' => 90, 'by_status' => ['pending' => ['payments' => 1, 'amount' => 90]], 'failure_reasons' => [], 'oldest_pending_hours' => 30.5, 'stuck_pending' => ['threshold_hours' => 24, 'payments' => 1, 'amount' => 90]],
            'items' => [['id' => 1, 'customer_name' => 'Wanjiru', 'amount' => 90, 'status' => 'pending', 'created_at' => '2026-09-19T10:00:00+03:00']], 'pagination' => $pagination],
        'purchases' => ['meta' => $meta, 'summary' => ['purchases' => 1, 'amount' => 100, 'by_type' => [], 'series' => []], 'items' => [['id' => 1, 'customer_name' => 'Otieno', 'type' => 'gift', 'amount' => 100, 'created_at' => '2026-09-19T10:00:00+03:00']], 'pagination' => $pagination],
        'games' => ['meta' => $meta, 'summary' => ['games' => 1, 'stakes' => 100, 'paid_to_players' => 90, 'refunded' => 0, 'house_take' => 10, 'by_outcome' => ['completed' => ['games' => 1, 'stakes' => 100, 'paid_to_players' => 90, 'refunded' => 0, 'house_take' => 10]], 'by_players' => []],
            'items' => [['id' => 1, 'game_id' => 'okupile', 'outcome' => 'completed', 'players' => 2, 'stakes' => 100, 'paid_to_players' => 90, 'house_take' => 10, 'escrow_balance' => 0, 'created_at' => '2026-09-19T10:00:00+03:00']], 'pagination' => $pagination],
        'competitions' => ['meta' => $meta, 'summary' => ['competitions' => 1, 'players' => 3, 'entries' => 750, 'house_cut' => 75, 'prizes_paid' => 0, 'outstanding' => 675, 'by_type_and_rounds' => []],
            'items' => [['cmp_uid' => 'tn|4|250', 'type' => 'tournament', 'rounds' => 4, 'players' => 3, 'entries' => 750, 'house_cut' => 75, 'prizes_paid' => 0, 'outstanding' => 675, 'unaccounted' => 0, 'started_at' => '2026-09-19T10:00:00+03:00']], 'pagination' => $pagination],
        'ledger' => ['meta' => $meta, 'summary' => ['entries' => 1, 'debit' => 0, 'credit' => 100, 'by_entry_type' => []],
            'items' => [['id' => 1, 'created_at' => '2026-09-19T10:00:00+03:00', 'entry_type' => 'game_bet', 'category' => 'stake', 'account' => 'game_escrow', 'wallet_type' => 'game_wallet', 'wallet_id' => 3, 'customer_id' => null, 'debit' => 0, 'credit' => 100, 'balance_after' => 100, 'status' => 'settled', 'reference' => 'GameTransaction#1']], 'pagination' => $pagination],
        'adjustments' => ['meta' => $meta, 'summary' => ['entries' => 1, 'credited' => 100, 'debited' => 0, 'net' => 100, 'without_reason' => 1, 'by_reason' => [], 'by_actor' => []],
            'items' => [['id' => 1, 'created_at' => '2026-09-19T10:00:00+03:00', 'customer_id' => 5, 'customer_name' => 'Kim', 'direction' => 'credit', 'amount' => 100, 'balance_before' => 0, 'balance_after' => 100, 'reason' => 'unspecified', 'actor' => 'api_key:1', 'operation' => 'update_customer_wallet']], 'pagination' => $pagination],
        'expenses' => ['meta' => $meta, 'summary' => ['active' => ['entries' => 1, 'amount' => 1500], 'voided' => ['entries' => 0, 'amount' => 0], 'by_category' => []],
            'items' => [['id' => 7, 'expense_date' => '2026-09-20', 'category' => 'hosting', 'amount' => 1500, 'description' => 'Server', 'reference' => 'INV-1', 'status' => 'active', 'entered_by' => 'api_key:4']], 'pagination' => $pagination],
        'customers/top' => ['meta' => $meta, 'summary' => ['customer_wallets_total' => 900, 'concentration' => ['top_wallets' => 10, 'balance' => 600, 'share_percent' => 66.7]],
            'items' => [['customer_id' => 502, 'customer_name' => 'Minks', 'deposited' => 0, 'withdrawn' => 0, 'staked' => 80, 'won' => 0, 'net_gaming' => -80, 'balance' => 1120]]],
    ];
}

/**
 * Answers any `/finance/{report}` GET with that report's fixture.
 */
function financeFixtureStub(): Closure
{
    $fixture = financeFixture();

    return fn ($request) => Http::response(['success' => true, 'data' => $fixture[trim(explode('?', explode('/finance/', $request->url())[1])[0], '/')] ?? []], 200);
}

/**
 * Replace the fakes with the given stubs ahead of the finance fixtures (the first matching stub wins).
 *
 * @param  array<string, mixed>  $stubs
 */
function fakeFinanceApi(array $stubs): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([...$stubs, '*/finance/*' => financeFixtureStub()]);
}

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);

    Http::fake([
        '*/finance/customers/*/statement*' => Http::response(['data' => [
            'customer' => ['id' => 5, 'name' => 'Kim', 'account_no' => 'KK-1'], 'wallet' => ['balance_now' => 100],
            'statement' => ['opening_balance' => 0, 'credits' => 100, 'debits' => 0, 'closing_balance' => 100, 'entries' => 1, 'reconciles' => true],
            'items' => [['id' => 1, 'created_at' => '2026-09-19T10:00:00+03:00', 'entry_type' => 'adjustment', 'category' => 'adjustment', 'debit' => 0, 'credit' => 100, 'balance_after' => 100, 'status' => 'settled', 'reason' => null]],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
        ]], 200),
        '*/finance/export/*' => Http::response("\xEF\xBB\xBFid\n1\n", 200, ['Content-Disposition' => 'attachment; filename="finance-ledger.csv"']),
        '*/finance/*' => financeFixtureStub(),
        '*/stats/retention' => Http::response(['data' => ['today' => 16, 'week' => 20, 'month' => 147, 'year' => 278, 'total_players' => 278]], 200),
        '*/customers/combined-leaderboard' => Http::response(['leaderboard' => [['id' => 1, 'name' => 'Ada Lovelace', 'single_game_wins' => 10, 'competition_wins' => 5, 'total_wins' => 15]]], 200),
    ]);

    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    $this->admin = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->admin->assignRole('super-admin');

    $this->manager = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->manager->assignRole('manager');
});

dataset('finance pages', [
    'income statement' => [IncomeStatementReport::class, 'Total Revenue'],
    'cash flow' => [CashFlowReport::class, 'Net Cash'],
    'balance sheet' => [BalanceSheetReport::class, 'Utility Account'],
    'trial balance' => [TrialBalanceReport::class, 'Out of balance'],
    'reconciliation' => [ReconciliationReport::class, 'Wallets match ledger'],
    'taxes' => [TaxesReport::class, 'Income tax'],
    'top customers' => [TopCustomersReport::class, 'Minks'],
    'deposits' => [DepositsPage::class, 'UIKEQ7VTP4'],
    'withdrawals' => [PlayerWithdrawalsPage::class, 'Wanjiru'],
    'purchases' => [PurchasesPage::class, 'Otieno'],
    'games' => [GamesReport::class, 'okupile'],
    'competitions' => [CompetitionsReport::class, 'tn|4|250'],
    'ledger' => [LedgerReport::class, 'game_escrow'],
    'adjustments' => [AdjustmentsReport::class, 'Kim'],
    'expenses' => [ApiExpensesReport::class, 'INV-1'],
    'excise duty' => [ExciseDutyReport::class, 'Unremitted since 12 Aug 2026'],
    'excise charges' => [ExciseChargesReport::class, 'QWE123XYZ'],
    'excise returns' => [ExciseReturnsReport::class, 'Overdue'],
    'kra remittances' => [ExciseRemittancesReport::class, 'KRA-REF-001'],
    'disputes' => [DisputesReport::class, '9b1c2f0e'],
]);

it('renders each finance report for an admin with data from the API', function (string $page, string $expected): void {
    $this->actingAs($this->admin);

    Livewire::test($page)
        ->assertOk()
        ->assertSee($expected)
        ->assertSee($page === BalanceSheetReport::class ? 'A note' : 'Ledger warning shown to admins.');
})->with('finance pages');

it('keeps amounts on one line in report tables and lets long text wrap', function (): void {
    fakeFinanceApi(['*/finance/reconciliation*' => Http::response(['data' => [
        'status' => 'fail', 'counts' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
        'checks' => [['key' => 'drift', 'title' => 'Wallets match ledger', 'status' => 'fail', 'count' => 33, 'amount' => 494817,
            'detail' => 'Customer wallet balances differ from the ledger by more than a shilling']],
    ]])]);
    $this->actingAs($this->admin);

    $html = Livewire::test(ReconciliationReport::class)->html();
    $cellIsOneLine = fn (string $text): bool => preg_match('/style="white-space: nowrap;?"(?:(?!fi-in-entry).)*?'.preg_quote($text, '/').'/s', $html) === 1;

    expect($cellIsOneLine('KES 494,817.00'))->toBeTrue()
        ->and($cellIsOneLine('Customer wallet balances differ from the ledger'))->toBeFalse();
});

it('labels each table value with its column name for the stacked phone layout', function (): void {
    $this->actingAs($this->admin);

    $labels = collect(Livewire::test(ReconciliationReport::class)->instance()->reportInfolist->getFlatComponents())
        ->map(fn ($component): ?string => method_exists($component, 'getLabel') ? (string) $component->getLabel() : null)
        ->filter()
        ->values();

    expect($labels)->toContain('Control', 'Amount', 'Detail')
        ->not->toContain('C0', 'C1');
});

it('blocks non-admin roles from every finance report', function (string $page): void {
    $this->actingAs($this->manager);

    get($page::getUrl())->assertForbidden();
})->with(fn () => collect(dataset_pages())->all());

function dataset_pages(): array
{
    return [
        IncomeStatementReport::class, CashFlowReport::class, BalanceSheetReport::class, TrialBalanceReport::class,
        ReconciliationReport::class, TaxesReport::class, TopCustomersReport::class,
        PlayerWithdrawalsPage::class, PurchasesPage::class, GamesReport::class, CompetitionsReport::class,
        LedgerReport::class, AdjustmentsReport::class, ApiExpensesReport::class, CustomerStatementReport::class,
        ExciseDutyReport::class, ExciseChargesReport::class, ExciseReturnsReport::class, ExciseRemittancesReport::class,
        DisputesReport::class,
    ];
}

it('degrades to an unavailable state instead of erroring when the finance API fails', function (): void {
    apiGoesDown();
    $this->actingAs($this->admin);

    Livewire::test(IncomeStatementReport::class)->assertOk()->assertSee('Game API unavailable');
});

it('sends the period, page size and table filter to the API for list reports', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(DepositsPage::class)
        ->set('tableFilters.status.value', '0')
        ->assertOk();

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/finance/deposits')
            && ($query['status'] ?? null) === '0'
            && ($query['per_page'] ?? null) === '25'
            && isset($query['from'], $query['to']);
    });
});

it('never asks the API for a range in the future or longer than a year', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CashFlowReport::class)->set('period', 'all_time')->assertOk();

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/finance/cash-flow')
            && $query['to'] === today()->toDateString()
            && Carbon::parse($query['from'])->diffInDays($query['to']) <= 365;
    });
});

it('shows a customer statement once a customer is chosen and none before', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CustomerStatementReport::class)->assertSee('Choose a customer to view a statement');
    Livewire::test(CustomerStatementReport::class, ['customerId' => 5])->assertSee('Reconciles')->assertSee('Kim');
});

it('lets super-admin open the wallet statement from a player profile', function (): void {
    $this->actingAs($this->admin);

    expect(CustomerStatementReport::canAccess())->toBeTrue();
});

it('shows the overall leaderboard from the combined endpoint', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(OverallLeaderboard::class)->assertOk()->assertSee('Ada Lovelace');
});

// --- CSV export ---

it('streams a finance csv to an admin without exposing the api key', function (): void {
    $this->actingAs($this->admin);

    $response = get(route('finance.export', ['report' => 'ledger', 'from' => '2026-09-01', 'to' => '2026-09-21']));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="finance-ledger.csv"');
    expect($response->getContent())->toContain('id');
    expect($response->headers->all())->not->toHaveKey('x-api-key');
});

it('rejects csv exports for guests and non-admins', function (): void {
    get(route('finance.export', ['report' => 'ledger']))->assertRedirect();

    $this->actingAs($this->manager);
    get(route('finance.export', ['report' => 'ledger']))->assertForbidden();
});

it('returns 404 for an export the API does not offer and 422 for a bad date', function (): void {
    $this->actingAs($this->admin);

    get(route('finance.export', ['report' => 'secrets']))->assertNotFound();
    get(route('finance.export', ['report' => 'ledger', 'from' => 'yesterday']))->assertSessionHasErrors('from');
});

// --- Dashboard widgets ---

it('shows the admin finance widgets to admins and hides them from other roles', function (string $widget): void {
    $this->actingAs($this->admin);
    expect($widget::canView())->toBeTrue();

    $this->actingAs($this->manager);
    expect($widget::canView())->toBeFalse();
})->with([
    FinanceOverviewWidget::class,
    PlatformPositionWidget::class,
    ExciseDutyWidget::class,
    PlayerEngagementWidget::class,
    StakesVersusPayoutsChartWidget::class,
    TopCustomersWidget::class,
]);

it('renders the finance overview and platform position widgets with API figures', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(FinanceOverviewWidget::class)->assertSee('KES 100')->assertSee('Revenue Today');
    Livewire::test(PlatformPositionWidget::class)
        ->assertSee('KES 122.51k')
        ->assertSee('FAIL')
        ->assertSee('B2C KES 28.77k')
        ->assertSee('Excise KES 25 · Disputes KES 40');
    Livewire::test(PlayerEngagementWidget::class)->assertSee('278');
    Livewire::test(TopCustomersWidget::class)->assertSee('Minks');
});

it('shows what is owed to KRA and flags overdue excise duty returns', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ExciseDutyWidget::class)
        ->assertSee('KES 18.00')
        ->assertSee('Unremitted since 12 Aug 2026')
        ->assertSee('KES 15.00')
        ->assertSee('On KES 300 deposits at 5%')
        ->assertSee('Overdue Returns')
        ->assertSee('KES 3.00 past the due date');
});

it('shows the next excise duty return when none is overdue', function (): void {
    Http::swap(new Factory);
    Http::fake(['*/finance/excise-duty/returns*' => Http::response(['success' => true, 'data' => ['items' => [
        ['period' => '2026-09', 'period_start' => '2026-09-01', 'due_date' => '2026-10-20', 'outstanding' => 12, 'overdue' => false],
    ]]])]);
    Http::fake(['*/finance/excise-duty*' => Http::response(['success' => true, 'data' => ['payable' => ['outstanding' => 12]]])]);
    $this->actingAs($this->admin);

    Livewire::test(ExciseDutyWidget::class)
        ->assertDontSee('Overdue Returns')
        ->assertSee('20 Oct 2026')
        ->assertSee('KES 12.00 for Sep 2026');
});

it('keeps the finance widgets rendering when the API is down', function (): void {
    apiGoesDown();
    $this->actingAs($this->admin);

    Livewire::test(FinanceOverviewWidget::class)->assertOk();
    Livewire::test(PlatformPositionWidget::class)->assertOk();
    Livewire::test(ExciseDutyWidget::class)->assertOk()->assertSee('Excise Payable');
    Livewire::test(PlayerEngagementWidget::class)->assertOk();
    Livewire::test(TopCustomersWidget::class)->assertOk()->assertSee('Top customers unavailable');
});

/**
 * Replace the happy-path fakes registered in beforeEach (the first matching stub wins).
 */
function apiGoesDown(): void
{
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['message' => 'Internal server error'], 500)]);
}

it('renders the overall summary widget with dashes when the stats API fails', function (): void {
    apiGoesDown();
    $this->actingAs($this->admin);

    Livewire::test(StatsOverview::class)->assertOk()->assertSee('Total Customers')->assertSee('—');
});

// --- Excise duty and disputes ---

it('sends the charge filters and a year of remittances to the API', function (): void {
    $this->travelTo('2026-09-23 12:00:00');
    $this->actingAs($this->admin);

    Livewire::test(ExciseChargesReport::class)
        ->filterTable('status', 'unremitted')
        ->filterTable('customer', ['customer_id' => 5])
        ->assertOk();
    Livewire::test(ExciseRemittancesReport::class)->assertOk();

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/finance/excise-duty/charges')
            && ($query['status'] ?? null) === 'unremitted'
            && ($query['customer_id'] ?? null) === '5';
    });
    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/finance/excise-duty/remittances')
            && ($query['status'] ?? null) === 'active'
            && ($query['from'] ?? null) === '2026-01-01';
    });
});

it('streams the excise duty and dispute exports', function (string $report): void {
    $this->actingAs($this->admin);

    get(route('finance.export', ['report' => $report]))->assertOk()->assertDownload();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), "/finance/export/{$report}"));
})->with(['excise-duty', 'excise-duty-charges', 'excise-duty-returns', 'excise-duty-remittances', 'disputes']);

it('fills the remittance from an outstanding return', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ExciseRemittancesReport::class)
        ->mountAction('recordRemittance')
        ->fillForm(['month' => '2026-08'])
        ->assertActionDataSet(['period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'amount_paid' => 3]);
});

it('records a KRA remittance with an idempotency key and shows it straight away', function (): void {
    $remitted = false;

    fakeFinanceApi([
        '*/finance/excise-duty/remittances' => function ($request) use (&$remitted) {
            if ($request->method() !== 'POST') {
                return null;
            }

            $remitted = true;

            return Http::response(['success' => true, 'data' => ['id' => 8, 'charges' => 1, 'amount_due' => 3, 'amount_paid' => 3, 'status' => 'active']], 201);
        },
        '*/finance/excise-duty/remittances?*' => function () use (&$remitted) {
            return Http::response(['success' => true, 'data' => ['items' => $remitted ? [['id' => 8, 'kra_reference' => 'KRA-NEW-777', 'status' => 'active']] : []]]);
        },
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ExciseRemittancesReport::class)
        ->assertDontSee('KRA-NEW-777')
        ->callAction('recordRemittance', data: [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'amount_paid' => 3,
            'kra_reference' => 'KRA-NEW-777',
            'paid_at' => '2026-09-18',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(Notification::make()->title('Remittance recorded')->body('1 charges attached · due KES 3.00 · paid KES 3.00.')->success())
        ->assertSee('KRA-NEW-777');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/finance/excise-duty/remittances')
        && $request['period_start'] === '2026-08-01'
        && $request['period_end'] === '2026-08-31'
        && (float) $request['amount_paid'] === 3.0
        && $request['kra_reference'] === 'KRA-NEW-777'
        && $request['paid_at'] === '2026-09-18'
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));
});

it('shows the API message when a remittance is refused', function (): void {
    fakeFinanceApi([
        '*/finance/excise-duty/remittances' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['success' => false, 'message' => 'There is no unremitted excise duty in this period.'], 422)
            : null,
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ExciseRemittancesReport::class)
        ->callAction('recordRemittance', data: ['period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'amount_paid' => 10, 'kra_reference' => 'KRA-X'])
        ->assertNotified(Notification::make()->title('Could not record the remittance')->body('There is no unremitted excise duty in this period.')->danger());
});

it('requires the period, amount and KRA reference before recording', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ExciseRemittancesReport::class)
        ->callAction('recordRemittance', data: ['period_start' => null, 'period_end' => null, 'amount_paid' => 0, 'kra_reference' => ''])
        ->assertHasActionErrors(['period_start' => 'required', 'period_end' => 'required', 'amount_paid' => 'min', 'kra_reference' => 'required']);

    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
});

it('voids a remittance with a reason', function (): void {
    fakeFinanceApi([
        '*/finance/excise-duty/remittances/*/void' => Http::response(['success' => true, 'data' => ['id' => 7, 'status' => 'voided']]),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ExciseRemittancesReport::class)
        ->callAction(TestAction::make('void')->table('7'), data: ['reason' => 'Recorded against the wrong month'])
        ->assertHasNoActionErrors()
        ->assertNotified('Remittance voided');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/void')
        && $request['reason'] === 'Recorded against the wrong month'
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));
});

it('requires a reason to void a remittance', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ExciseRemittancesReport::class)
        ->callAction(TestAction::make('void')->table('7'), data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
});

it('reports a remittance that was already voided', function (): void {
    fakeFinanceApi([
        '*/finance/excise-duty/remittances/*/void' => Http::response(['success' => false, 'message' => 'Remittance is already voided.'], 409),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ExciseRemittancesReport::class)
        ->callAction(TestAction::make('void')->table('7'), data: ['reason' => 'Duplicate entry'])
        ->assertNotified(Notification::make()->title('Remittance already changed')->body('Remittance is already voided.')->warning());
});

it('hides remittance writes from admins without the remit permission', function (): void {
    Role::findByName('super-admin')->revokePermissionTo('excise-duty.remit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($this->admin->fresh());

    Livewire::test(ExciseRemittancesReport::class)
        ->assertActionHidden('recordRemittance')
        ->assertActionHidden(TestAction::make('void')->table('7'));
});

it('opens the complaint from a disputes row', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(DisputesReport::class)
        ->assertSee(ComplaintDetailPage::getUrl(['complaint' => 3]), escape: false);
});
