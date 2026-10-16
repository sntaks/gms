<?php

use App\Enums\UserStatus;
use App\Filament\Pages\BetShopDetailPage;
use App\Livewire\BetShopKeysTable;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\get;

const ISSUED_SHOP_KEY = 'shop_Xk2pQ8vT4mN7rL1wZ9yB3cD6fH0jx9Qa';

/**
 * Shop 3 as KadiApi returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shopDetailPayload(array $overrides = []): array
{
    return [
        'id' => 3, 'shop_no' => 'S101', 'float_bill_ref' => 'SHOP-S101', 'name' => 'Kariobangi Bet Shop',
        'owner_name' => 'Peter Mwangi', 'owner_phone' => '254712345678', 'location' => 'Kariobangi North',
        'status' => 'active', 'status_reason' => null, 'notes' => 'Opposite the market', 'float_balance' => 0.00, 'active_keys' => 1,
        'created_by' => 'api_key:2', 'created_at' => '2026-10-16T09:00:00+03:00', 'updated_at' => '2026-10-16T09:00:00+03:00',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shopKeyPayload(array $overrides = []): array
{
    return [
        'id' => 7, 'name' => 'Front desk', 'bet_shop_id' => 3, 'key_hint' => '…x9Qa', 'is_active' => true,
        'created_at' => '2026-10-16T09:05:00+03:00', 'revoked_at' => null, 'revoked_by' => null,
        ...$overrides,
    ];
}

function isShopWrite(Request $request, string $suffix): bool
{
    return $request->method() === 'POST' && str_ends_with($request->url(), $suffix);
}

/**
 * Fake KadiApi for shop 3. `$shop` answers `GET /bet-shops/{id}` (one response, or a list
 * returned in turn as the page reloads); `$stubs` answer the writes and go first.
 *
 * @param  array<string, mixed>|list<array<string, mixed>>  $shop
 * @param  array<string, mixed>  $stubs
 */
function fakeShopDetailApi(array $shop = [], array $stubs = []): void
{
    Http::preventStrayRequests();

    $shops = array_is_list($shop) && $shop !== [] ? $shop : [shopDetailPayload($shop)];
    $reads = 0;

    Http::fake($stubs + [
        '*/bet-shops/*/keys*' => Http::response(['success' => true, 'data' => [
            'items' => [shopKeyPayload()],
            'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1],
        ]]),
        '*/bet-shops/*' => function (Request $request) use ($shops, &$reads) {
            if ($request->method() === 'PUT') {
                return Http::response(['success' => true, 'message' => 'Shop updated', 'data' => shopDetailPayload(['name' => 'Kariobangi Bets'])]);
            }

            return Http::response(['success' => true, 'data' => $shops[min($reads++, count($shops) - 1)]]);
        },
    ]);
}

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);

    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    $this->admin = User::factory()->create(['name' => 'Grace Admin', 'status' => UserStatus::Active->value]);
    $this->admin->assignRole('admin');

    $this->manager = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->manager->assignRole('manager');
});

it('shows the shop with its status, float and bill ref', function (): void {
    fakeShopDetailApi(['float_balance' => 12500.00]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->assertSee('S101 · Kariobangi Bet Shop')
        ->assertSee('Active')
        ->assertSee('KES 12,500.00')
        ->assertSee('SHOP-S101')
        ->assertSee('Opposite the market');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && ! str_contains($request->url(), '/bet-shops/3'));
});

it('returns 404 for a shop KadiApi does not know', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*/bet-shops/*' => Http::response(['success' => false, 'message' => 'Shop not found'], 404)]);
    $this->actingAs($this->admin);

    get(BetShopDetailPage::getUrl(['shop' => 999]))->assertNotFound();
});

it('edits the shop details without sending the shop number', function (): void {
    fakeShopDetailApi();
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->callAction('editShop', data: ['name' => 'Kariobangi Bets', 'location' => ''])
        ->assertHasNoActionErrors()
        ->assertNotified('Shop details saved');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data() === ['name' => 'Kariobangi Bets', 'owner_name' => 'Peter Mwangi', 'owner_phone' => '254712345678', 'location' => null, 'notes' => 'Opposite the market']);

    expect(AuditLog::query()->where('auditable_type', 'KadiApi\\BetShop')->sole()->event)->toBe('updated');
});

it('suspends the shop with a reason and an idempotency key, then shows it suspended', function (): void {
    fakeShopDetailApi([shopDetailPayload(), shopDetailPayload(['status' => 'suspended', 'status_reason' => 'Owner unreachable'])], [
        '*/bet-shops/*/suspend' => Http::response(['success' => true, 'message' => 'Shop suspended', 'data' => shopDetailPayload(['status' => 'suspended'])]),
    ]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->callAction('suspendShop', data: ['reason' => 'Owner unreachable'])
        ->assertHasNoActionErrors()
        ->assertNotified('Shop S101 suspended')
        ->assertSee('Reason: Owner unreachable')
        ->assertActionHidden('suspendShop')
        ->assertActionVisible('activateShop');

    Http::assertSent(fn (Request $request): bool => isShopWrite($request, '/suspend')
        && $request->data() === ['reason' => 'Owner unreachable']
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));

    $log = AuditLog::query()->where('auditable_type', 'KadiApi\\BetShop')->sole();
    expect($log->user_id)->toBe($this->admin->id)
        ->and($log->auditable_id)->toBe(3)
        ->and($log->event)->toBe('suspended')
        ->and($log->new_values['payload']['reason'])->toBe('Owner unreachable');
});

it('shows KadiApi\'s message and refreshes when activating a closed shop is refused', function (): void {
    fakeShopDetailApi([shopDetailPayload(['status' => 'suspended']), shopDetailPayload(['status' => 'closed'])], [
        '*/bet-shops/*/activate' => Http::response(['success' => false, 'code' => 'shop_closed', 'message' => 'This shop is closed and cannot be reactivated.', 'data' => shopDetailPayload(['status' => 'closed'])], 409),
    ]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->callAction('activateShop')
        ->assertNotified(Notification::make()->title('The shop is closed')->body('This shop is closed and cannot be reactivated.')->warning())
        ->assertActionNotMounted('activateShop')
        ->assertActionHidden('activateShop');

    Http::assertSent(fn (Request $request): bool => isShopWrite($request, '/activate') && $request->data() === []);

    $log = AuditLog::query()->where('auditable_type', 'KadiApi\\BetShop')->sole();
    expect($log->event)->toBe('activate_failed')
        ->and($log->new_values['status'])->toBe(409)
        ->and($log->new_values['response']['code'])->toBe('shop_closed');
});

it('disables closing while the float holds money', function (): void {
    fakeShopDetailApi(['float_balance' => 300.00]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->assertActionDisabled('closeShop');
});

it('closes the shop only after the shop number is typed and finality is confirmed', function (): void {
    fakeShopDetailApi([shopDetailPayload(), shopDetailPayload(['status' => 'closed', 'active_keys' => 0])], [
        '*/bet-shops/*/close' => Http::response(['success' => true, 'message' => 'Shop closed', 'data' => shopDetailPayload(['status' => 'closed'])]),
    ]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->mountAction('closeShop')
        ->fillForm(['reason' => 'Owner retired', 'confirm_shop_no' => 'S102', 'confirm_final' => false])
        ->callMountedAction()
        ->assertHasActionErrors(['confirm_shop_no' => 'That is not this shop\'s number.', 'confirm_final' => 'Confirm that closing is final.'])
        ->fillForm(['confirm_shop_no' => 's101', 'confirm_final' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Shop S101 closed')
        ->assertActionHidden('closeShop')
        ->assertActionHidden('issueKey');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => isShopWrite($request, '/close') && $request->data() === ['reason' => 'Owner retired']);
});

it('shows KadiApi\'s message when the float turns out not to be empty', function (): void {
    fakeShopDetailApi([shopDetailPayload(), shopDetailPayload(['float_balance' => 950.00])], [
        '*/bet-shops/*/close' => Http::response(['success' => false, 'code' => 'float_not_empty', 'message' => 'The float still holds KES 950.00. Record a redemption first.', 'data' => shopDetailPayload(['float_balance' => 950.00])], 409),
    ]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->callAction('closeShop', data: ['reason' => 'Owner retired', 'confirm_shop_no' => 'S101', 'confirm_final' => true])
        ->assertNotified(Notification::make()->title('The float is not empty')->body('The float still holds KES 950.00. Record a redemption first.')->warning())
        ->assertSee('KES 950.00')
        ->assertActionDisabled('closeShop');

    expect(AuditLog::query()->where('auditable_type', 'KadiApi\\BetShop')->sole()->event)->toBe('close_failed');
});

it('issues a key without an idempotency key and shows it once without keeping it anywhere', function (): void {
    fakeShopDetailApi(stubs: [
        '*/bet-shops/*/keys' => Http::response(['success' => true, 'message' => 'Key issued. Store it now: it is not shown again.', 'data' => shopKeyPayload(['id' => 8, 'key' => ISSUED_SHOP_KEY])], 201),
    ]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $this->actingAs($this->admin);

    $page = Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->callAction('issueKey', data: ['name' => 'Front desk'])
        ->assertHasNoActionErrors()
        ->assertActionMounted('issuedKey')
        ->assertMountedActionModalSee([BetShopDetailPage::KEY_WARNING, ISSUED_SHOP_KEY]);

    Http::assertSent(fn (Request $request): bool => isShopWrite($request, '/keys')
        && $request->data() === ['name' => 'Front desk']
        && ! $request->hasHeader('Idempotency-Key'));

    $log = AuditLog::query()->where('auditable_type', 'KadiApi\\BetShopKey')->sole();
    expect($log->event)->toBe('issued')
        ->and($log->auditable_id)->toBe(8)
        ->and($log->new_values['response'])->not->toHaveKey('key')
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain(ISSUED_SHOP_KEY)
        ->and(json_encode(session()->all()))->not->toContain(ISSUED_SHOP_KEY)
        ->and(implode("\n", $logged))->not->toContain(ISSUED_SHOP_KEY);

    // Closing the modal drops the key from the page's state.
    $page->call('unmountAction')
        ->assertActionNotMounted('issuedKey');

    expect(json_encode($page->get('mountedActions')))->not->toContain(ISSUED_SHOP_KEY);
});

it('never retries a key that timed out and asks to check the key list instead', function (): void {
    fakeShopDetailApi(stubs: ['*/bet-shops/*/keys' => Http::failedConnection()]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->callAction('issueKey', data: ['name' => 'Front desk'])
        ->assertNotified(Notification::make()->title('The key may have been issued')->body(BetShopDetailPage::KEY_TIMEOUT_WARNING)->warning()->persistent())
        ->assertActionNotMounted('issueKey')
        ->assertDispatched(BetShopKeysTable::REFRESH_EVENT);

    expect(Http::recorded(fn (Request $request): bool => isShopWrite($request, '/keys')))->toHaveCount(1)
        ->and(AuditLog::query()->where('auditable_type', 'KadiApi\\BetShopKey')->sole()->event)->toBe('issue_failed');
});

it('shows KadiApi\'s message when a key is refused for a closed shop', function (): void {
    fakeShopDetailApi(stubs: [
        '*/bet-shops/*/keys' => Http::response(['success' => false, 'code' => 'shop_closed', 'message' => 'This shop is closed.'], 409),
    ]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->callAction('issueKey', data: ['name' => 'Front desk'])
        ->assertNotified(Notification::make()->title('The shop is closed')->body('This shop is closed.')->warning())
        ->assertActionNotMounted('issuedKey');
});

it('lists the shop\'s keys from KadiApi\'s paged list', function (): void {
    fakeShopDetailApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopKeysTable::class, ['shopId' => 3])
        ->assertSee('Front desk')
        ->assertSee('…x9Qa')
        ->assertSee('Active');

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/keys') && $query === ['page' => '1', 'per_page' => '10'];
    });
});

it('revokes a key after confirmation and names the GMS user who revoked it', function (): void {
    $revoked = shopKeyPayload(['is_active' => false, 'revoked_at' => '2026-10-16T11:00:00+03:00', 'revoked_by' => 'api_key:2']);
    $isRevoked = false;
    fakeShopDetailApi(stubs: [
        '*/bet-shop-keys/*/revoke' => function () use ($revoked, &$isRevoked) {
            $isRevoked = true;

            return Http::response(['success' => true, 'message' => 'Key revoked', 'data' => $revoked]);
        },
        // The table reloads on every render, so it answers with the key as it stands.
        '*/bet-shops/*/keys*' => function () use ($revoked, &$isRevoked) {
            return Http::response(['success' => true, 'data' => [
                'items' => [$isRevoked ? $revoked : shopKeyPayload()],
                'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1],
            ]]);
        },
    ]);
    $this->actingAs($this->admin);

    Livewire::test(BetShopKeysTable::class, ['shopId' => 3])
        ->callAction(TestAction::make('revokeKey')->table('7'))
        ->assertNotified('Key Front desk revoked')
        ->assertDispatched(BetShopDetailPage::REFRESH_EVENT)
        ->assertSee('Revoked')
        ->assertSee('Grace Admin')
        ->assertActionHidden(TestAction::make('revokeKey')->table('7'));

    Http::assertSent(fn (Request $request): bool => isShopWrite($request, '/revoke')
        && ! str_contains($request->url(), '/bet-shop-keys/7/')
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));

    $log = AuditLog::query()->where('auditable_type', 'KadiApi\\BetShopKey')->sole();
    expect($log->auditable_id)->toBe(7)
        ->and($log->event)->toBe('revoked');
});

it('lets managers view a shop and its keys but take no action', function (): void {
    fakeShopDetailApi(['status' => 'suspended']);
    $this->actingAs($this->manager);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->assertSee('SHOP-S101')
        ->assertActionHidden('issueKey')
        ->assertActionHidden('editShop')
        ->assertActionHidden('activateShop')
        ->assertActionHidden('closeShop');

    Livewire::test(BetShopKeysTable::class, ['shopId' => 3])
        ->assertSee('Front desk')
        ->assertActionHidden(TestAction::make('revokeKey')->table('7'));
});

it('forbids the shop page to agents', function (): void {
    $agent = User::factory()->create(['status' => UserStatus::Active->value]);
    $agent->assignRole('agent');
    $this->actingAs($agent);

    get(BetShopDetailPage::getUrl(['shop' => 3]))->assertForbidden();
});
