<?php

use App\Filament\Pages\ExciseRemittancesReport;
use App\Filament\Pages\HouseFundingReport;
use App\Filament\Pages\OpenWalletsReport;
use App\Filament\Pages\ReferralSummaryReport;
use App\Filament\Pages\WinningsTaxChargesReport;
use App\Filament\Pages\WinningsTaxRemittancesReport;
use App\Filament\Pages\WinningsTaxReport;
use App\Filament\Pages\WinningsTaxReturnsReport;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
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
    travelTo('2026-11-16 10:00:00');
});

function financeData(array $data): array
{
    return ['success' => true, 'data' => ['meta' => ['warnings' => []], ...$data]];
}

function paged(array $items, array $summary = []): array
{
    return financeData(['summary' => $summary, 'items' => $items, 'pagination' => ['page' => 1, 'per_page' => 50, 'total' => count($items), 'last_page' => 1]]);
}

it('sums up winnings tax withheld, reversed, paid and still payable', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/finance/winnings-tax?*' => Http::response(financeData([
        'totals' => ['charges' => 40, 'tax_charged' => 8000.0, 'tax_reversed' => 200.0, 'tax_net' => 7800.0, 'tax_remitted' => 5000.0],
        'payable' => ['outstanding' => 2800.0, 'oldest_unremitted_at' => '2026-10-02T08:00:00+03:00'],
        'series' => [['period' => '2026-11-01', 'charges' => 40, 'tax_charged' => 8000.0, 'tax_reversed' => 200.0, 'tax_net' => 7800.0, 'tax_remitted' => 5000.0]],
        'notes' => ['tax_charged is the winnings tax withheld in the period.'],
    ]))]);

    get(WinningsTaxReport::getUrl())
        ->assertSee('KES 8,000.00')
        ->assertSee('KES 2,800.00')
        ->assertSee('Oldest from 02 Oct 2026')
        ->assertSee('tax_charged is the winnings tax withheld in the period.');
});

it('lists winnings tax charges with their kind and payment status filters', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/finance/winnings-tax/charges*' => Http::response(paged([
        ['id' => 1, 'charged_at' => '2026-11-15T20:00:00+03:00', 'kind' => 'charge', 'wager_id' => 9, 'wager_reference' => 'spin-7f2c', 'settlement_version' => 1, 'product' => 'slots_fruit', 'customer_id' => 5501, 'customer_name' => 'Wanjiku Kamau', 'tax_base' => 5000.0, 'rate' => 0.2, 'amount' => 1000.0, 'remittance_id' => null, 'kra_reference' => null],
    ], ['rows' => 1, 'tax_net' => 1000.0, 'by_kind' => ['charge' => ['rows' => 1, 'tax_base' => 5000.0, 'tax' => 1000.0]], 'unremitted' => ['rows' => 1, 'tax' => 1000.0]]))]);

    Livewire::test(WinningsTaxChargesReport::class)
        ->filterTable('kind', 'charge')
        ->filterTable('status', 'unremitted')
        ->assertSee('Wanjiku Kamau')
        ->assertSee('20%')
        ->assertSee('Not yet paid');

    Http::assertSent(fn (Request $request): bool => ($request['kind'] ?? null) === 'charge' && ($request['status'] ?? null) === 'unremitted');
});

it('shows monthly winnings tax returns with what is still due', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/finance/winnings-tax/returns*' => Http::response(financeData([
        'filing_day' => 20, 'payable' => 2800.0,
        'items' => [['period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_date' => '2026-11-20', 'charges' => 30, 'tax_charged' => 6000.0, 'tax_reversed' => 0.0, 'tax_due' => 6000.0, 'tax_remitted' => 3200.0, 'outstanding' => 2800.0, 'amount_to_remit' => 2800.0, 'overdue' => false]],
    ]))]);

    Livewire::test(WinningsTaxReturnsReport::class)
        ->assertSee('KES 6,000.00')
        ->assertSee('KES 3,200.00')
        ->assertDontSee('Gross Deposits');
});

it('records a winnings tax remittance on the winnings tax endpoint', function (): void {
    signInWithRole('finance');
    Http::fake([
        'https://game-api.test/finance/winnings-tax/remittances' => Http::response(['success' => true, 'data' => ['id' => 4, 'charges' => 30, 'amount_due' => 2800.0, 'amount_paid' => 2800.0, 'kra_reference' => 'KRA-WHT-1']], 201),
        'https://game-api.test/finance/winnings-tax/*' => Http::response(paged([], ['active' => [], 'voided' => [], 'payable' => 2800.0])),
    ]);

    Livewire::test(WinningsTaxRemittancesReport::class)
        ->callAction('recordRemittance', data: ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_paid' => 2800, 'kra_reference' => 'KRA-WHT-1'])
        ->assertHasNoActionErrors();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/finance/winnings-tax/remittances')
        && $request['kra_reference'] === 'KRA-WHT-1'
        && $request->hasHeader('Idempotency-Key'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'excise-duty/remittances') && $request->method() === 'POST');
});

it('lets finance remit winnings tax and excise duty, and nobody without the permission', function (): void {
    signInWithRole('finance');
    expect(WinningsTaxRemittancesReport::canRemit())->toBeTrue()
        ->and(ExciseRemittancesReport::canRemit())->toBeTrue();

    signInWithRole('manager');
    expect(WinningsTaxRemittancesReport::canRemit())->toBeFalse();
});

it('shows the money held in open game wallets for any period', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/finance/open-wallets*' => Http::response(paged([
        ['kind' => 'tournament', 'wallet_id' => 3, 'game_id' => null, 'cmp_uid' => 'T-2026-11', 'players' => null, 'rounds' => 4, 'balance' => 12000.0, 'under_complaint' => true, 'last_activity_at' => '2026-11-14T10:00:00+03:00', 'idle_minutes' => 2880],
    ], ['total' => ['wallets' => 1, 'held' => 12000.0, 'oldest_activity_at' => '2026-11-14T10:00:00+03:00'], 'games' => [], 'tournaments' => ['wallets' => 1, 'held' => 12000.0], 'jackpots' => []]))]);

    Livewire::test(OpenWalletsReport::class)
        ->assertSee('T-2026-11')
        ->assertSee('Under complaint')
        ->assertSee('KES 12,000.00');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'open-wallets') && ! isset($request['from']));
});

it('voids house funding entered in error and shows why the API refused one', function (bool $accepted): void {
    signInWithRole('finance');
    Http::fake([
        'https://game-api.test/finance/house-funding/*/void' => $accepted
            ? Http::response(['success' => true, 'message' => 'Voided', 'data' => []])
            : Http::response(['success' => false, 'code' => 'insufficient_house_balance', 'message' => 'The house wallet no longer holds this amount.'], 409),
        'https://game-api.test/finance/house-funding*' => Http::response(paged([
            ['id' => 12, 'deposit_id' => 900, 'trans_id' => 'RKA1B2C3D4', 'bill_ref_no' => 'HOUSE', 'amount' => 50000.0, 'note' => 'Float top-up', 'voided_at' => null, 'recorded_by' => 'api_key:2', 'created_at' => '2026-11-10 09:00:00'],
        ], ['active' => ['entries' => 1, 'amount' => 50000.0], 'voided' => ['entries' => 0, 'amount' => 0.0], 'house_wallet_balance' => 80000.0])),
    ]);

    $page = Livewire::test(HouseFundingReport::class)
        ->callAction(TestAction::make('voidHouseFunding')->table('12'), data: ['reason' => 'Recorded twice']);

    $accepted
        ? $page->assertNotified('House funding voided')
        : $page->assertNotified(Notification::make()->title('Could not void the funding')->body('The house wallet no longer holds this amount.')->warning());
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/void') && $request['reason'] === 'Recorded twice' && $request->hasHeader('Idempotency-Key'));
})->with(['accepted' => [true], 'refused' => [false]]);

it('sums up the referral programme\'s money', function (): void {
    signInWithRole('finance');
    Http::fake(['https://game-api.test/finance/referrals?*' => Http::response(financeData([
        'bonuses_earned' => ['signup' => 300.0, 'first_deposit' => 500.0, 'total' => 800.0, 'count' => 80],
        'payouts' => ['paid' => 450.0, 'pending' => 100.0, 'failed' => 0.0],
        'position' => ['unspent_balances' => 1200.0, 'open_withdrawals' => ['count' => 2, 'amount' => 100.0], 'lifetime_bonuses' => 9000.0, 'lifetime_paid_out' => 6000.0],
        'notes' => ['payouts.paid is the expense.'],
    ]))]);

    get(ReferralSummaryReport::getUrl())
        ->assertSee('KES 800.00')
        ->assertSee('KES 1,200.00')
        ->assertSee('payouts.paid is the expense.');
});
