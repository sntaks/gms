<?php

use App\Enums\UserStatus;
use App\Filament\Pages\BetShopDetailPage;
use App\Filament\Pages\BetShopsPage;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\get;

/**
 * A bet shop as KadiApi returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function betShopPayload(array $overrides = []): array
{
    return [
        'id' => 3,
        'shop_no' => 'S101',
        'float_bill_ref' => 'SHOP-S101',
        'name' => 'Kariobangi Bet Shop',
        'owner_name' => 'Peter Mwangi',
        'owner_phone' => '254712345678',
        'location' => 'Kariobangi North',
        'status' => 'active',
        'status_reason' => null,
        'notes' => null,
        'float_balance' => 12500.00,
        'active_keys' => 1,
        'created_by' => 'api_key:2',
        'created_at' => '2026-10-16T09:00:00+03:00',
        'updated_at' => '2026-10-16T09:00:00+03:00',
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $shops
 * @return array<string, mixed>
 */
function betShopList(array $shops): array
{
    return ['success' => true, 'data' => [
        'items' => $shops,
        'pagination' => ['page' => 1, 'per_page' => 50, 'total' => count($shops), 'last_page' => 1],
    ]];
}

function isBetShopCreateRequest(Request $request): bool
{
    return $request->method() === 'POST' && str_ends_with($request->url(), '/bet-shops');
}

/**
 * Fake the shop list, plus the given create response.
 */
function fakeBetShopsListApi(mixed $create = null): void
{
    Http::preventStrayRequests();

    Http::fake([
        '*/bet-shops*' => fn (Request $request) => $request->method() === 'POST'
            ? (is_callable($create) ? $create($request) : $create ?? Http::response(['success' => true, 'data' => betShopPayload(['id' => 9, 'shop_no' => 'S200', 'float_bill_ref' => 'SHOP-S200', 'float_balance' => 0, 'active_keys' => 0])], 201))
            : Http::response(betShopList([betShopPayload()])),
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

it('lists shops with their float, keys and bill ref and links each to its page', function (): void {
    fakeBetShopsListApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopsPage::class)
        ->assertSee('S101')
        ->assertSee('Kariobangi Bet Shop')
        ->assertSee('****5678')
        ->assertDontSee('254712345678')
        ->assertSee('KES 12,500.00')
        ->assertSee('SHOP-S101')
        ->assertSeeHtml(e(BetShopDetailPage::getUrl(['shop' => 3])));
});

it('passes the status tab to KadiApi and sends none for all shops', function (): void {
    fakeBetShopsListApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopsPage::class)
        ->call('setTab', 'suspended');

    $statuses = Http::recorded(fn (Request $request): bool => $request->method() === 'GET')
        ->map(function (array $pair): ?string {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query['status'] ?? null;
        })
        ->values()
        ->all();

    expect($statuses)->toBe([null, 'suspended']);
});

it('shows an empty state instead of failing when KadiApi is down', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*/bet-shops*' => Http::response(['message' => 'Server Error'], 500)]);
    $this->actingAs($this->admin);

    Livewire::test(BetShopsPage::class)
        ->assertSee('Bet shops unavailable');
});

it('onboards a shop with an idempotency key, logs the write and opens the shop', function (): void {
    fakeBetShopsListApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopsPage::class)
        ->callAction('createBetShop', data: [
            'shop_no' => ' s200 ',
            'name' => 'Umoja Bet Shop',
            'owner_name' => 'Jane Akinyi',
            'owner_phone' => '0712 345 678',
            'location' => 'Umoja II',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(Notification::make()->title('Shop S200 created')->body('The owner buys float by paying the paybill with bill ref SHOP-S200.')->success())
        ->assertRedirect(BetShopDetailPage::getUrl(['shop' => 9]));

    Http::assertSent(fn (Request $request): bool => isBetShopCreateRequest($request)
        && $request->data() === ['shop_no' => 'S200', 'name' => 'Umoja Bet Shop', 'owner_name' => 'Jane Akinyi', 'owner_phone' => '0712345678', 'location' => 'Umoja II']
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));

    $log = AuditLog::query()->where('auditable_type', 'KadiApi\\BetShop')->sole();
    expect($log->user_id)->toBe($this->admin->id)
        ->and($log->auditable_id)->toBe(9)
        ->and($log->event)->toBe('created')
        ->and($log->new_values['payload']['shop_no'])->toBe('S200')
        ->and($log->new_values['status'])->toBe(201);
});

it('validates the shop form before calling KadiApi', function (array $data, array $errors): void {
    fakeBetShopsListApi();
    $this->actingAs($this->admin);

    Livewire::test(BetShopsPage::class)
        ->callAction('createBetShop', data: [
            'shop_no' => 'S200', 'name' => 'Umoja Bet Shop', 'owner_name' => 'Jane Akinyi', 'owner_phone' => '0712345678',
            ...$data,
        ])
        ->assertHasActionErrors($errors);

    Http::assertNotSent(fn (Request $request): bool => isBetShopCreateRequest($request));
})->with([
    'shop number too short' => [['shop_no' => 'S'], ['shop_no' => 'The shop number must be 2 to 10 letters or digits.']],
    'shop number with a dash' => [['shop_no' => 'S-200'], ['shop_no' => 'The shop number must be 2 to 10 letters or digits.']],
    'not a Kenyan mobile' => [['owner_phone' => '0201234567'], ['owner_phone' => 'Enter a Kenyan mobile number, e.g. 0712345678.']],
    'no owner' => [['owner_name' => ''], ['owner_name' => 'required']],
]);

it('maps KadiApi 422 errors onto the form and uses a new idempotency key for the corrected submit', function (): void {
    fakeBetShopsListApi(create: Http::sequence()
        ->push(['message' => 'The shop no has already been taken.', 'errors' => ['shop_no' => ['The shop no has already been taken.']]], 422)
        ->push(['success' => true, 'data' => betShopPayload(['id' => 9, 'shop_no' => 'S201'])], 201));
    $this->actingAs($this->admin);

    Livewire::test(BetShopsPage::class)
        ->mountAction('createBetShop')
        ->fillForm(['shop_no' => 'S101', 'name' => 'Umoja Bet Shop', 'owner_name' => 'Jane Akinyi', 'owner_phone' => '0712345678'])
        ->callMountedAction()
        ->assertHasActionErrors(['shop_no' => 'The shop no has already been taken.'])
        ->assertActionMounted('createBetShop')
        ->fillForm(['shop_no' => 'S201'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $keys = Http::recorded(fn (Request $request): bool => isBetShopCreateRequest($request))
        ->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])
        ->unique();

    expect($keys)->toHaveCount(2)
        ->and(AuditLog::query()->where('event', 'create_failed')->sole()->new_values['status'])->toBe(422);
});

it('keeps the form open and reuses the idempotency key after a rate limit', function (): void {
    fakeBetShopsListApi(create: Http::sequence()
        ->push(['message' => 'Too Many Attempts.'], 429)
        ->push(['success' => true, 'data' => betShopPayload(['id' => 9, 'shop_no' => 'S200'])], 201));
    $this->actingAs($this->admin);

    Livewire::test(BetShopsPage::class)
        ->mountAction('createBetShop')
        ->fillForm(['shop_no' => 'S200', 'name' => 'Umoja Bet Shop', 'owner_name' => 'Jane Akinyi', 'owner_phone' => '0712345678'])
        ->callMountedAction()
        ->assertNotified(Notification::make()->title('Could not create the shop')->body('Rate limit reached. Please retry shortly.')->danger())
        ->assertActionMounted('createBetShop')
        ->callMountedAction()
        ->assertNotified('Shop S200 created');

    $keys = Http::recorded(fn (Request $request): bool => isBetShopCreateRequest($request))
        ->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])
        ->unique();

    expect($keys)->toHaveCount(1);
});

it('lets managers view shops but not onboard them', function (): void {
    fakeBetShopsListApi();
    $this->actingAs($this->manager);

    Livewire::test(BetShopsPage::class)
        ->assertSee('S101')
        ->assertActionHidden('createBetShop');
});

it('forbids the page to agents, who can view customers but not bet shops', function (): void {
    $agent = User::factory()->create(['status' => UserStatus::Active->value]);
    $agent->assignRole('agent');
    $this->actingAs($agent);

    get(BetShopsPage::getUrl())->assertForbidden();
});
