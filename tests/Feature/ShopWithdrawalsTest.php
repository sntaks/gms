<?php

use App\Enums\UserStatus;
use App\Filament\Pages\ShopWithdrawalsPage;
use App\Livewire\ShopWithdrawalsTable;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;

/**
 * Grace asked to collect KES 1,000 at S101; the request expires at 10:00 on 17 Oct.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shopWithdrawalPayload(array $overrides = []): array
{
    return [
        'withdrawal_id' => '9d2f6c1e-3b7a-4c55-8e0d-2a1f4b6c7d8e', 'shop_no' => 'S101', 'shop_name' => 'Kariobangi Bet Shop',
        'amount' => 1000.00, 'status' => 'pending', 'expires_at' => '2026-10-17T10:00:00+03:00', 'requested_at' => '2026-10-16T10:00:00+03:00',
        'closed_at' => null, 'player' => ['player_no' => '12345671', 'name' => 'Grace W****'], 'failed_attempts' => 2, 'close_reason' => null,
        'id' => 18, 'bet_shop_id' => 3, 'customer_id' => 900, 'customer_name' => 'Grace Wanjiku', 'requested_by' => 'api_key:2', 'closed_by' => null,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $items
 */
function fakeShopWithdrawalsApi(array $items = []): void
{
    Http::preventStrayRequests();

    $page = ['success' => true, 'data' => [
        'items' => $items ?: [shopWithdrawalPayload()],
        'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1],
    ]];

    Http::fake([
        '*/customers/*/shop-withdrawals*' => Http::response($page),
        '*/bet-shops/*' => Http::response($page),
    ]);
}

/**
 * @return list<array<string, string>>
 */
function shopWithdrawalQueries(): array
{
    return Http::recorded()
        ->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        })
        ->values()
        ->all();
}

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo('2026-10-16 12:00:00');

    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);

    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    $this->manager = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->manager->assignRole('manager');
});

it('lists every shop\'s cash withdrawals with the player, attempts and expiry', function (): void {
    fakeShopWithdrawalsApi();
    $this->actingAs($this->manager);

    Livewire::test(ShopWithdrawalsTable::class)
        ->assertSee('Grace Wanjiku')
        ->assertSee('1234 5671')
        ->assertSee('Kariobangi Bet Shop')
        ->assertSee('KES 1,000.00')
        ->assertSee('Pending')
        ->assertSee('17 Oct 2026, 10:00');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bet-shops/withdrawals?'));
});

it('opens on the status in the link and passes the filters to KadiApi', function (): void {
    fakeShopWithdrawalsApi();
    $this->actingAs($this->manager);

    Livewire::test(ShopWithdrawalsTable::class, ['initialStatus' => 'pending'])
        ->filterTable('customer', ['customer_id' => 900])
        ->filterTable('requested', ['from' => '2026-10-01', 'to' => '2026-10-16']);

    expect(shopWithdrawalQueries())
        ->toContain(['status' => 'pending', 'page' => '1', 'per_page' => '50'])
        ->toContain(['status' => 'pending', 'customer_id' => '900', 'from' => '2026-10-01', 'to' => '2026-10-16', 'page' => '1', 'per_page' => '50']);
});

it('reads one player\'s withdrawals from their own endpoint', function (): void {
    fakeShopWithdrawalsApi([shopWithdrawalPayload(['status' => 'paid', 'closed_at' => '2026-10-16T11:00:00+03:00'])]);
    $this->actingAs($this->manager);

    Livewire::test(ShopWithdrawalsTable::class, ['customerId' => 900])
        ->assertSee('Paid')
        ->assertSee('S101');

    Http::assertSent(fn (Request $request): bool => preg_match('#/customers/[^/]+/shop-withdrawals\?#', $request->url()) === 1
        && ! str_contains($request->url(), '/customers/900/'));
});

it('flags a pending request only once it is well past its expiry', function (array $overrides, bool $isStale): void {
    expect(ShopWithdrawalsTable::isStale(shopWithdrawalPayload($overrides)))->toBe($isStale);
})->with([
    'not yet expired' => [['expires_at' => '2026-10-16T16:00:00+03:00'], false],
    'expired 10 minutes ago, the job may not have run yet' => [['expires_at' => '2026-10-16T11:50:00+03:00'], false],
    'expired 20 minutes ago' => [['expires_at' => '2026-10-16T11:40:00+03:00'], true],
    'paid, so nothing is held' => [['status' => 'paid', 'expires_at' => '2026-10-16T09:00:00+03:00'], false],
]);

it('shows the stale warning on a pending request the expiry job missed', function (): void {
    fakeShopWithdrawalsApi([shopWithdrawalPayload(['expires_at' => '2026-10-16T09:00:00+03:00'])]);
    $this->actingAs($this->manager);

    Livewire::test(ShopWithdrawalsTable::class)
        ->assertSee('the shop-withdrawals:expire scheduler may not be running');
});

it('opens the pending list from its link and forbids agents', function (): void {
    fakeShopWithdrawalsApi();
    $this->actingAs($this->manager);

    get(ShopWithdrawalsPage::getUrlForStatus('pending'))->assertOk();

    $agent = User::factory()->create(['status' => UserStatus::Active->value]);
    $agent->assignRole('agent');
    $this->actingAs($agent);

    get(ShopWithdrawalsPage::getUrl())->assertForbidden();
});
