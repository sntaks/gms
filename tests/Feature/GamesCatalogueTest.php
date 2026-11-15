<?php

use App\Filament\Pages\GameKeysPage;
use App\Filament\Pages\GameProductsPage;
use App\Filament\Pages\GamesPage;
use App\Filament\Pages\GameWagerDetailPage;
use App\Filament\Pages\GameWagersPage;
use App\Filament\Pages\HouseBankedReport;
use App\Models\AuditLog;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;

const GAME_API = 'https://game-api.test';

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    config([
        'services.game_api.url' => GAME_API,
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);
    Cache::flush();
    Http::preventStrayRequests();
});

function gamesCatalogue(): array
{
    return ['data' => [
        ['code' => 'slots', 'name' => 'Slots', 'launch_url' => 'https://slots.kadi.test', 'status' => 'active', 'sort_order' => 2,
            'products' => [['code' => 'slots_fruit', 'game' => 'slots', 'name' => 'Fruit Party', 'revenue_model' => 'house_banked', 'status' => 'active', 'min_stake' => 10.0, 'max_stake' => 1000.0, 'max_payout' => null, 'winnings_tax_rate' => 0.2]]],
    ]];
}

/**
 * @param  array<string, mixed>  $wager
 */
function wagerDetail(array $wager = []): array
{
    return ['success' => true, 'data' => [
        'wager' => ['id' => 9, 'customer_id' => 5501, 'reference' => 'spin-7f2c', 'game' => 'slots', 'product' => 'slots_fruit', 'funding' => 'cash', 'stake' => 50.0, 'free_bet_amount' => 0.0, 'status' => 'open', 'settlement_version' => 0, 'payout' => 0.0, 'bonus' => 0.0, 'tax' => 0.0, 'round_reference' => null, 'placed_at' => '2026-11-16T09:00:00+03:00', 'settled_at' => null, 'is_tombstone' => false, ...$wager],
        'settlements' => [],
        'ledger_entries' => [['entry_type' => 'stake', 'wallet_type' => 'wallet', 'debit' => 50.0, 'credit' => 0.0, 'balance_after' => 950.0, 'created_at' => '2026-11-16T09:00:00+03:00']],
    ]];
}

it('shows the catalogue to finance and managers and edits a game as admin', function (): void {
    signInWithRole('admin');
    Http::fake([
        GAME_API.'/games/slots' => Http::response(['success' => true, 'data' => ['code' => 'slots']]),
        GAME_API.'/games*' => Http::response(gamesCatalogue()),
    ]);

    Livewire::test(GamesPage::class)
        ->assertSee('Fruit Party')
        ->callAction(TestAction::make('editGame')->table('slots'), data: ['name' => 'Slots', 'launch_url' => 'https://slots.kadi.test', 'status' => 'suspended', 'sort_order' => 2])
        ->assertHasNoActionErrors()
        ->assertNotified('Slots saved');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['status'] === 'suspended');
});

it('keeps managers to viewing the catalogue', function (): void {
    signInWithRole('manager');
    Http::fake([GAME_API.'/games*' => Http::response(gamesCatalogue())]);

    Livewire::test(GamesPage::class)->assertActionHidden(TestAction::make('editGame')->table('slots'));
});

it('removes a product limit when it is left empty and sends the tax rate as a fraction', function (): void {
    signInWithRole('admin');
    Http::fake([
        GAME_API.'/products/slots_fruit' => Http::response(['success' => true, 'data' => ['code' => 'slots_fruit']]),
        GAME_API.'/products*' => Http::response(['data' => gamesCatalogue()['data'][0]['products']]),
        GAME_API.'/games*' => Http::response(gamesCatalogue()),
    ]);

    Livewire::test(GameProductsPage::class)
        ->mountAction(TestAction::make('editProduct')->table('slots_fruit'))
        ->assertActionDataSet(['winnings_tax_percent' => 20.0, 'max_payout' => null])
        ->setActionData(['max_stake' => '', 'winnings_tax_percent' => '12.5'])
        ->callMountedAction()
        ->assertNotified('Fruit Party saved');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && array_key_exists('max_stake', $request->data()) && $request['max_stake'] === null
        && $request['winnings_tax_rate'] === 0.125
        && $request['min_stake'] === 10.0);
});

it('shows an issued game key once, never logs it and never retries', function (): void {
    signInWithRole('super-admin');
    Http::fake([
        GAME_API.'/games/slots/keys' => Http::response(['success' => true, 'data' => ['id' => 31, 'name' => 'Slots server', 'scope' => 'game', 'game' => 'slots', 'is_active' => true, 'key' => 'kk_game_SECRET123']], 201),
        GAME_API.'/api-keys*' => Http::response(['success' => true, 'data' => ['items' => [], 'pagination' => []]]),
        GAME_API.'/games*' => Http::response(gamesCatalogue()),
    ]);

    Livewire::test(GameKeysPage::class)
        ->callAction('issueKey', data: ['game' => 'slots', 'name' => 'Slots server'])
        ->assertActionMounted('issuedKey')
        ->assertMountedActionModalSee('kk_game_SECRET123');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/games/slots/keys') && ! $request->hasHeader('Idempotency-Key'));
    expect(json_encode(AuditLog::query()->where('auditable_type', 'KadiApi\\ApiKey')->sole()->new_values))->not->toContain('kk_game_SECRET123');
});

it('warns that a key may exist when issuing timed out', function (): void {
    signInWithRole('super-admin');
    Http::fake([
        GAME_API.'/games/slots/keys' => Http::failedConnection(),
        GAME_API.'/api-keys*' => Http::response(['success' => true, 'data' => ['items' => [], 'pagination' => []]]),
        GAME_API.'/games*' => Http::response(gamesCatalogue()),
    ]);

    Livewire::test(GameKeysPage::class)
        ->callAction('issueKey', data: ['game' => 'slots', 'name' => 'Slots server'])
        ->assertNotified('The key may have been issued');

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/keys')))->toHaveCount(1);
});

it('keeps game keys to super-admins', function (string $role): void {
    signInWithRole($role);

    get(GameKeysPage::getUrl())->assertForbidden();
})->with(['admin', 'finance', 'manager']);

it('searches wagers by reference and customer', function (): void {
    signInWithRole('finance');
    Http::fake([
        GAME_API.'/games*' => Http::response(gamesCatalogue()),
        GAME_API.'/wagers*' => Http::response(['success' => true, 'data' => ['items' => [wagerDetail()['data']['wager']], 'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1]]]),
    ]);

    Livewire::test(GameWagersPage::class)
        ->filterTable('lookup', ['reference' => 'spin-7f2c', 'customer' => 5501])
        ->assertSee('spin-7f2c');

    Http::assertSent(fn (Request $request): bool => ($request['reference'] ?? null) === 'spin-7f2c' && ($request['customer'] ?? null) == 5501);
});

it('voids an open casino wager once and shows its ledger', function (): void {
    $finance = signInWithRole('finance', ['name' => 'Fatuma Finance']);
    Http::fake([
        GAME_API.'/wagers/9/void' => Http::response(['success' => true, 'data' => ['wager' => ['id' => 9, 'status' => 'void'], 'settlement' => [], 'replayed' => false]]),
        GAME_API.'/wagers/9/detail' => Http::sequence()->push(wagerDetail())->push(wagerDetail(['status' => 'void'])),
    ]);

    Livewire::test(GameWagerDetailPage::class, ['wager' => 9])
        ->assertSee('stake · wallet · −KES 50.00 → KES 950.00')
        ->callAction('voidWager', data: ['reason' => 'Game server crashed mid-spin'])
        ->assertNotified('Wager voided');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/wagers/9/void')
        && $request->data() === ['reason' => 'Game server crashed mid-spin', 'admin_user' => "Fatuma Finance (GMS #{$finance->id})"]);
});

it('never voids a sportsbook or prediction wager here and says where to go instead', function (string $product, string $pointer): void {
    signInWithRole('finance');
    Http::fake([GAME_API.'/wagers/9/detail' => Http::response(wagerDetail(['product' => $product, 'game' => 'sportsbook']))]);

    Livewire::test(GameWagerDetailPage::class, ['wager' => 9])
        ->assertActionHidden('voidWager')
        ->assertSee($pointer);
})->with([
    'pre-match sports bet' => ['sportsbook', 'Sportsbook › Bets'],
    'in-play sports bet' => ['sportsbook_live', 'Sportsbook › Bets'],
    'jackpot entry' => ['sportsbook_jackpot', 'Sportsbook › Bets'],
    'prediction pool' => ['predict_pool', 'Predictions › Markets'],
]);

it('shows the Kadi API\'s refusal when the wager is no longer open', function (): void {
    signInWithRole('finance');
    Http::fake([
        GAME_API.'/wagers/9/void' => Http::response(['success' => false, 'message' => 'Only an open wager can be voided', 'code' => 'wager_not_open', 'retryable' => false], 409),
        GAME_API.'/wagers/9/detail' => Http::response(wagerDetail()),
    ]);

    Livewire::test(GameWagerDetailPage::class, ['wager' => 9])
        ->callAction('voidWager', data: ['reason' => 'Crash'])
        ->assertNotified(Notification::make()->title('Could not void the wager')->body('Only an open wager can be voided')->warning());
});

it('reports house-banked games per product with RTP and hold', function (): void {
    signInWithRole('finance');
    Http::fake([GAME_API.'/finance/house-banked*' => Http::response(['success' => true, 'data' => [
        'totals' => ['bets' => 1200, 'players' => 340, 'turnover' => 60000.0, 'payouts' => 55200.0, 'ggr' => 4800.0, 'rtp' => 92.0, 'hold' => 8.0, 'net' => 4500.0, 'winnings_tax_withheld' => 900.0],
        'by_product' => [['product' => 'slots_fruit', 'game' => 'slots', 'bets' => 1200, 'voids' => 3, 'turnover' => 60000.0, 'payouts' => 55200.0, 'rtp' => 92.0, 'ggr' => 4800.0, 'hold' => 8.0, 'bonuses' => 0.0, 'free_bet_winnings' => 300.0, 'net' => 4500.0]],
        'notes' => ['rtp is payouts as a percentage of turnover.'],
    ]])]);

    get(HouseBankedReport::getUrl())
        ->assertSee('slots_fruit (slots)')
        ->assertSee('92.0%')
        ->assertSee('KES 4,800.00')
        ->assertSee('rtp is payouts as a percentage of turnover.');
});
