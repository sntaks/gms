<?php

use App\Filament\Pages\ResponsibleGamblingFlagsPage;
use App\Filament\Pages\WithdrawalApprovalsPage;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Livewire\CustomerCompliancePanel;
use App\Livewire\CustomerPredictBetsTable;
use App\Livewire\CustomerSportsBetsTable;
use App\Models\AuditLog;
use App\Models\Site\SbBet;
use App\Models\Site\SiteUser;
use App\Services\GameApiService;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\SiteSchema;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);
    Http::preventStrayRequests();
    travelTo('2026-11-16 12:00:00');
});

/**
 * A withdrawal held for approval, as the Kadi API presents it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function heldWithdrawal(array $overrides = []): array
{
    return [
        'withdrawal_id' => 77, 'customer_id' => 5501, 'player_no' => '10045501', 'name' => 'Wanjiku Kamau', 'amount' => 45000.0,
        'phone_no' => '254712345678', 'status' => 'awaiting', 'requested_by' => 'player', 'requested_at' => '2026-11-15T08:00:00+03:00',
        'admin_user' => null, 'decided_by' => null, 'reason' => null, 'decided_at' => null, 'payout_status' => 'pending', 'payout_error' => null,
        ...$overrides,
    ];
}

function fakeHeld(array $more = []): void
{
    Http::fake([
        ...$more,
        'https://game-api.test/withdrawals/pending*' => Http::response(['success' => true, 'data' => ['items' => [heldWithdrawal()], 'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1]]]),
    ]);
}

it('lists held withdrawals and flags one held over a day', function (): void {
    signInWithRole('finance');
    fakeHeld();

    Livewire::test(WithdrawalApprovalsPage::class)
        ->assertSee('Wanjiku Kamau')
        ->assertSee('KES 45,000.00');

    expect(WithdrawalApprovalsPage::isOverdue(heldWithdrawal()))->toBeTrue()
        ->and(WithdrawalApprovalsPage::isOverdue(heldWithdrawal(['requested_at' => '2026-11-16T11:00:00+03:00'])))->toBeFalse();
});

it('approves a held withdrawal and pays it out', function (): void {
    $finance = signInWithRole('finance', ['name' => 'Fatuma Finance']);
    fakeHeld(['https://game-api.test/withdrawals/77/approve' => Http::response(['success' => true, 'message' => 'Approved: the payout was sent to M-Pesa', 'data' => heldWithdrawal(['status' => 'approved', 'payout_status' => 'sent'])])]);

    Livewire::test(WithdrawalApprovalsPage::class)
        ->callAction(TestAction::make('approveWithdrawal')->table('77'))
        ->assertNotified('Approved');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/withdrawals/77/approve') && $request['admin_user'] === "Fatuma Finance (GMS #{$finance->id})");
});

it('tells finance exactly what happened to the money when M-Pesa does not pay', function (int $status, array $body, string $title): void {
    signInWithRole('finance');
    fakeHeld(['https://game-api.test/withdrawals/77/approve' => Http::response($body, $status)]);

    Livewire::test(WithdrawalApprovalsPage::class)
        ->callAction(TestAction::make('approveWithdrawal')->table('77'))
        ->assertNotified($title);

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/approve')))->toHaveCount(1)
        ->and(AuditLog::query()->where('auditable_type', 'KadiApi\\WithdrawalApproval')->sole()->new_values['status'])->toBe($status);
})->with([
    'M-Pesa refused, player refunded' => [502, ['success' => false, 'message' => 'Approved, but M-Pesa refused the payout; the player has been refunded'], 'M-Pesa refused the payout'],
    'M-Pesa never answered' => [504, ['success' => false, 'code' => 'payout_unknown', 'message' => 'Approved, but M-Pesa did not answer'], 'Payout not confirmed'],
    'already decided' => [409, ['success' => false, 'code' => 'not_awaiting', 'message' => 'This withdrawal is not awaiting approval'], 'Could not approve'],
]);

it('never resends an approval whose answer was lost', function (): void {
    signInWithRole('finance');
    fakeHeld(['https://game-api.test/withdrawals/77/approve' => Http::failedConnection()]);

    Livewire::test(WithdrawalApprovalsPage::class)
        ->callAction(TestAction::make('approveWithdrawal')->table('77'))
        ->assertNotified(Notification::make()->title('Not confirmed')->body(WithdrawalApprovalsPage::NO_ANSWER)->warning()->persistent());

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/approve')))->toHaveCount(1);
});

it('rejects a held withdrawal with a reason', function (): void {
    signInWithRole('finance');
    fakeHeld(['https://game-api.test/withdrawals/77/reject' => Http::response(['success' => true, 'data' => heldWithdrawal(['status' => 'rejected'])])]);

    Livewire::test(WithdrawalApprovalsPage::class)
        ->callAction(TestAction::make('rejectWithdrawal')->table('77'), data: ['reason' => 'Account under fraud review'])
        ->assertNotified('Rejected');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/reject') && $request['reason'] === 'Account under fraud review');
});

it('lets managers see held withdrawals but not decide them, and keeps agents out', function (): void {
    signInWithRole('manager');
    fakeHeld();

    Livewire::test(WithdrawalApprovalsPage::class)
        ->assertActionHidden(TestAction::make('approveWithdrawal')->table('77'))
        ->assertActionHidden(TestAction::make('rejectWithdrawal')->table('77'));

    signInWithRole('agent');
    get(WithdrawalApprovalsPage::getUrl())->assertForbidden();
});

it('lists open responsible-gambling flags and closes one with a note', function (): void {
    signInWithRole('finance');
    Http::fake([
        'https://game-api.test/responsible-gambling/flags/5/resolve' => Http::response(['success' => true, 'data' => ['id' => 5]]),
        'https://game-api.test/responsible-gambling/flags*' => Http::response(['success' => true, 'data' => ['items' => [
            ['id' => 5, 'type' => 'rg_deposit_while_excluded', 'customer_id' => 5501, 'details' => ['amount' => 2000, 'trans_id' => 'RKA1B2C3D4'], 'created_at' => '2026-11-16T10:00:00+03:00', 'resolved_at' => null],
        ], 'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1]]]),
    ]);

    Livewire::test(ResponsibleGamblingFlagsPage::class)
        ->assertSee('Deposit during an exclusion')
        ->callAction(TestAction::make('resolveFlag')->table('5'), data: ['note' => 'Refunded through the paybill'])
        ->assertNotified('Flag closed');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && ($request['resolved'] ?? null) == 0);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/resolve') && $request['note'] === 'Refunded through the paybill');
});

function fakeCompliancePanel(array $more = []): void
{
    Http::fake([
        ...$more,
        '*/responsible-gambling' => Http::response(['success' => true, 'data' => [
            'status' => 1, 'can_play' => false,
            'exclusion' => ['type' => 'cool_off', 'ends_at' => '2026-11-23T12:00:00+03:00'],
            'deposit_limits' => [['period' => 'day', 'amount' => 5000.0, 'pending_amount' => null, 'pending_from' => null, 'remaining' => 3000.0]],
            'deposit_remaining' => 3000.0,
        ]]),
        '*/exclusions' => Http::response(['success' => true, 'data' => ['items' => [['type' => 'cool_off', 'duration' => '7d', 'starts_at' => '2026-11-16T12:00:00+03:00', 'ends_at' => '2026-11-23T12:00:00+03:00']], 'pagination' => []]]),
        '*/status-changes' => Http::response(['success' => true, 'data' => ['items' => [['from_status' => 6, 'to_status' => 1, 'reason' => 'KYC done', 'admin_user' => 'Jane Admin (GMS #3)', 'actor' => null, 'created_at' => '2026-11-10T09:00:00+03:00']], 'pagination' => []]]),
    ]);
}

it('shows a player\'s status, break, limits and history', function (): void {
    signInWithRole('agent');
    fakeCompliancePanel();

    Livewire::test(CustomerCompliancePanel::class, ['customerId' => 5501])
        ->assertSee('Active')
        ->assertSee('Cannot play')
        ->assertSee('Cool-off until 23 Nov 2026, 12:00')
        ->assertSee('Day: KES 5,000.00 · KES 3,000.00 left')
        ->assertSee('Lead → Active')
        ->assertActionHidden('changeStatus')
        ->assertActionHidden('startBreak');
});

it('changes a player\'s status with a reason', function (): void {
    $finance = signInWithRole('finance', ['name' => 'Fatuma Finance']);
    fakeCompliancePanel(['*/status' => Http::response(['success' => true, 'data' => ['status' => 5, 'previous_status' => 1]])]);

    Livewire::test(CustomerCompliancePanel::class, ['customerId' => 5501])
        ->callAction('changeStatus', data: ['status' => 5, 'reason' => 'Chargeback ring'])
        ->assertNotified('Status: Fraud');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data() === ['status' => 5, 'reason' => 'Chargeback ring', 'admin_user' => "Fatuma Finance (GMS #{$finance->id})"]);
});

it('shows why a shorter break was refused', function (): void {
    signInWithRole('finance');
    fakeCompliancePanel(['*/exclusion' => Http::response(['success' => false, 'code' => 'exclusion_shorter', 'message' => 'The current break already lasts longer. A break can be made longer, but not shorter.'], 422)]);

    Livewire::test(CustomerCompliancePanel::class, ['customerId' => 5501])
        ->callAction('startBreak', data: ['type' => 'cool_off', 'duration' => '24h'])
        ->assertNotified(Notification::make()->title('Could not start the break')->body('The current break already lasts longer. A break can be made longer, but not shorter.')->warning());
});

it('adds compliance and bet tabs to the customer page', function (): void {
    signInWithRole('agent');
    $this->mock(GameApiService::class)
        ->shouldReceive('getCustomer')->andReturn(['id' => 42, 'name' => 'Jane Doe', 'status' => 1])
        ->shouldReceive('getCustomerGamesPlayed')->andReturn(['single_games' => [], 'tournament_games' => [], 'jackpot_games' => []])
        ->shouldReceive('getCustomerTransactions')->andReturn(['transactions' => []])
        ->shouldReceive('getCustomerPurchases')->andReturn([]);

    Livewire::test(ViewAccount::class, ['record' => 42])
        ->assertSee('Compliance')
        ->assertSee('Sports bets')
        ->assertSee('Prediction bets');
});

it('lists only this player\'s sports bets from the Kadi site', function (): void {
    SiteSchema::create();
    signInWithRole('agent');
    $player = SiteUser::factory()->create(['linked_id' => 5501]);
    $mine = SbBet::factory()->create(['user_id' => $player->id]);
    $theirs = SbBet::factory()->create();

    Livewire::test(CustomerSportsBetsTable::class, ['customerId' => 5501])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('lists a player\'s prediction bets', function (): void {
    signInWithRole('agent');
    Http::fake(['*/predict/bets*' => Http::response(['success' => true, 'data' => ['items' => [
        ['id' => 3, 'market_id' => 42, 'question' => 'Will Harambee Stars qualify for AFCON 2027?', 'side' => 'yes', 'stake' => 500.0, 'status' => 'open', 'multiplier' => 1.49, 'payout' => null, 'placed_at' => '2026-11-16T09:00:00+03:00'],
    ], 'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1]]])]);

    Livewire::test(CustomerPredictBetsTable::class, ['customerId' => 5501])
        ->assertSee('Will Harambee Stars qualify for AFCON 2027?')
        ->assertSee('On Yes')
        ->assertSee('1.49x');
});
