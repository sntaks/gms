<?php

use App\Enums\UserStatus;
use App\Filament\Pages\BetShopDetailPage;
use App\Filament\Pages\ShopLoadsPage;
use App\Filament\Resources\Accounts\AccountResource;
use App\Livewire\ShopLoadsTable;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\get;

/**
 * Load 41: Grace paid KES 1,000 cash at S101 and got KES 950.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shopLoadPayload(array $overrides = []): array
{
    return [
        'id' => 41, 'load_id' => '5b1c7f2e-0f1d-4a2b-9a51-6f3e2c1d0b9a', 'shop_no' => 'S101',
        'player' => ['player_no' => '12345671', 'name' => 'Grace K****', 'active' => true],
        'gross_amount' => 1000.00, 'excise_amount' => 50.00, 'net_amount' => 950.00, 'status' => 'completed',
        'loaded_at' => '2026-10-16T10:15:00+03:00', 'reversed_at' => null,
        'bet_shop_id' => 3, 'customer_id' => 900, 'customer_name' => 'Grace Wanjiku Kamau', 'wallet_id' => 900,
        'loaded_by' => 'api_key:7', 'reversed_by' => null, 'reversal_reason' => null,
        ...$overrides,
    ];
}

/**
 * Fake the loads lists and one load. `$stubs` answer the writes and go first.
 *
 * @param  array<string, mixed>  $stubs
 */
function fakeShopLoadsApi(array $stubs = []): void
{
    Http::preventStrayRequests();

    Http::fake($stubs + [
        '*/bet-shops/loads?*' => Http::response(['success' => true, 'data' => [
            'items' => [shopLoadPayload()],
            'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1],
        ]]),
        '*/bet-shops/loads/*' => Http::response(['success' => true, 'data' => shopLoadPayload()]),
        '*/bet-shops/*/loads*' => Http::response(['success' => true, 'data' => [
            'items' => [shopLoadPayload()],
            'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1],
        ]]),
    ]);
}

/**
 * The query string of every loads list request KadiApi was sent.
 *
 * @return list<array<string, string>>
 */
function shopLoadsQueries(): array
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), 'loads?'))
        ->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        })
        ->values()
        ->all();
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

it('lists every shop\'s loads with the player, the shop and the amounts', function (): void {
    fakeShopLoadsApi();
    $this->actingAs($this->admin);

    Livewire::test(ShopLoadsTable::class)
        ->assertSee('Grace Wanjiku Kamau')
        ->assertSee('1234 5671')
        ->assertSee('S101')
        ->assertSee('KES 1,000.00')
        ->assertSee('KES 50.00')
        ->assertSee('KES 950.00')
        ->assertSee('API key #7')
        ->assertSeeHtml(e(AccountResource::getUrl('view', ['record' => 900])))
        ->assertSeeHtml(e(BetShopDetailPage::getUrl(['shop' => 3])));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bet-shops/loads?'));
});

it('passes the status, customer and date filters to KadiApi', function (): void {
    fakeShopLoadsApi();
    $this->actingAs($this->admin);

    Livewire::test(ShopLoadsTable::class)
        ->filterTable('status', 'reversed')
        ->filterTable('customer', ['customer_id' => 900])
        ->filterTable('loaded', ['from' => '2026-10-01', 'to' => '2026-10-16']);

    expect(shopLoadsQueries())->toContain(['status' => 'reversed', 'customer_id' => '900', 'from' => '2026-10-01', 'to' => '2026-10-16', 'page' => '1', 'per_page' => '50']);
});

it('reads one shop\'s loads on its Loads tab and one player\'s on their Shop loads tab', function (): void {
    fakeShopLoadsApi();
    $this->actingAs($this->admin);

    Livewire::test(ShopLoadsTable::class, ['shopId' => 3])->assertSee('Grace Wanjiku Kamau');
    Livewire::test(ShopLoadsTable::class, ['customerId' => 900])->assertSee('S101');

    Http::assertSent(fn (Request $request): bool => preg_match('#/bet-shops/[^/]+/loads\?#', $request->url()) === 1
        && ! str_contains($request->url(), '/bet-shops/3/'));
    expect(shopLoadsQueries())->toContain(['customer_id' => '900', 'page' => '1', 'per_page' => '10']);
});

it('opens one load from a link, e.g. from an excise duty charge', function (): void {
    fakeShopLoadsApi();
    $this->actingAs($this->admin);

    Livewire::test(ShopLoadsTable::class, ['loadId' => 41])
        ->assertSet('defaultAction', 'viewLoad')
        ->assertSet('defaultActionArguments', ['load' => 41])
        ->mountAction('viewLoad', ['load' => 41])
        ->assertMountedActionModalSee(['5b1c7f2e-0f1d-4a2b-9a51-6f3e2c1d0b9a', 'Grace Wanjiku Kamau', 'KES 1,000.00']);

    Http::assertSent(fn (Request $request): bool => preg_match('#/bet-shops/loads/[^/?]+$#', $request->url()) === 1
        && ! str_ends_with($request->url(), '/loads/41'));
});

it('reverses a load by its encrypted id with a reason and an idempotency key, and logs it', function (): void {
    fakeShopLoadsApi([
        '*/bet-shops/loads/*/reverse' => Http::response(['success' => true, 'message' => 'Load reversed', 'data' => shopLoadPayload(['status' => 'reversed', 'reversed_by' => 'api_key:2', 'reversal_reason' => 'Wrong player'])]),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ShopLoadsTable::class)
        ->mountAction(TestAction::make('reverseLoad')->table('41'))
        ->assertMountedActionModalSee(ShopLoadsTable::REVERSE_NOTE)
        ->fillForm(['reason' => 'Wrong player'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified(Notification::make()
            ->title('Load reversed')
            ->body('KES 1,000.00 was taken back from Grace Wanjiku Kamau\'s wallet and returned to S101\'s float.')
            ->success())
        ->assertDispatched(BetShopDetailPage::REFRESH_EVENT);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/reverse')
        && ! str_contains($request->url(), '/loads/41/')
        && ! str_contains($request->url(), '5b1c7f2e')
        && $request->data() === ['reason' => 'Wrong player']
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));

    $log = AuditLog::query()->where('auditable_type', 'KadiApi\\BetShopLoad')->sole();
    expect($log->user_id)->toBe($this->admin->id)
        ->and($log->auditable_id)->toBe(41)
        ->and($log->event)->toBe('reversed')
        ->and($log->new_values['payload']['reason'])->toBe('Wrong player');
});

it('shows KadiApi\'s refusal when a load cannot be reversed', function (string $code, string $message, string $title): void {
    fakeShopLoadsApi([
        '*/bet-shops/loads/*/reverse' => Http::response(['success' => false, 'code' => $code, 'message' => $message, 'data' => shopLoadPayload()], 409),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ShopLoadsTable::class)
        ->callAction(TestAction::make('reverseLoad')->table('41'), data: ['reason' => 'Wrong player'])
        ->assertNotified(Notification::make()->title($title)->body($message)->warning())
        ->assertActionNotMounted(TestAction::make('reverseLoad')->table('41'));

    $log = AuditLog::query()->where('auditable_type', 'KadiApi\\BetShopLoad')->sole();
    expect($log->event)->toBe('reverse_failed')
        ->and($log->new_values['response']['code'])->toBe($code);
})->with([
    'player spent it' => ['player_has_spent', 'The player has already spent this money, so the load cannot be reversed', 'The player has already spent this money'],
    'excise remitted' => ['excise_remitted', 'The excise duty on this load is already in a KRA remittance.', 'The excise duty is already remitted to KRA'],
    'already reversed' => ['already_reversed', 'This load is already reversed.', 'Load already reversed'],
]);

it('offers no reversal for a load already reversed', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*/bet-shops/loads?*' => Http::response(['success' => true, 'data' => [
        'items' => [shopLoadPayload(['status' => 'reversed', 'reversed_at' => '2026-10-16T11:00:00+03:00', 'reversed_by' => 'api_key:2', 'reversal_reason' => 'Wrong player'])],
        'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1],
    ]])]);
    $this->actingAs($this->admin);

    Livewire::test(ShopLoadsTable::class)
        ->assertSee('Wrong player')
        ->assertActionHidden(TestAction::make('reverseLoad')->table('41'));
});

it('lets managers see loads but not reverse them', function (): void {
    fakeShopLoadsApi();
    $this->actingAs($this->manager);

    get(ShopLoadsPage::getUrl())->assertOk();

    Livewire::test(ShopLoadsTable::class)
        ->assertSee('Grace Wanjiku Kamau')
        ->assertActionHidden(TestAction::make('reverseLoad')->table('41'));
});

it('forbids the loads page to agents', function (): void {
    $agent = User::factory()->create(['status' => UserStatus::Active->value]);
    $agent->assignRole('agent');
    $this->actingAs($agent);

    get(ShopLoadsPage::getUrl())->assertForbidden();
});
