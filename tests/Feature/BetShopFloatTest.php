<?php

use App\Enums\UserStatus;
use App\Filament\Pages\BetShopDetailPage;
use App\Filament\Pages\UnmatchedDepositsPage;
use App\Livewire\ShopFloatPurchasesTable;
use App\Livewire\ShopFloatRedemptionsTable;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * An owner's KES 10,000 paybill payment for shop S101, still unmatched.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shopFloatDeposit(array $overrides = []): array
{
    return [
        'id' => 5531, 'trans_id' => 'UJK8H2L1QX', 'trans_time' => '2026-10-16T09:30:00+03:00', 'amount' => 10000.0,
        'bill_ref_no' => 'SHOP-S101', 'msisdn' => '2547****5678', 'name' => 'PETER MWANGI', 'status' => 'unmatched',
        'created_at' => '2026-10-16T09:30:02+03:00', 'suggestions' => [], 'resolution' => null,
        'bet_shop' => ['id' => 3, 'shop_no' => 'S101', 'name' => 'Kariobangi Bet Shop', 'status' => 'active'],
        ...$overrides,
    ];
}

/**
 * The same payment once credited to S101's float.
 *
 * @return array<string, mixed>
 */
function creditedShopFloatDeposit(): array
{
    return shopFloatDeposit([
        'status' => 'completed',
        'resolution' => [
            'action' => 'shop_float', 'customer_id' => null, 'customer_name' => null, 'note' => 'Checked M-Pesa statement — by Grace Admin',
            'resolved_by' => 'api_key:2', 'resolved_at' => '2026-10-16T09:45:00+03:00',
            'float_purchase_id' => 12, 'bet_shop_id' => 3, 'shop_no' => 'S101', 'shop_name' => 'Kariobangi Bet Shop',
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function floatPurchasePayload(array $overrides = []): array
{
    return [
        'id' => 12, 'bet_shop_id' => 3, 'deposit_id' => 5531, 'trans_id' => 'UJK8H2L1QX', 'bill_ref_no' => 'SHOP-S101',
        'amount' => 10000.00, 'note' => 'Checked M-Pesa statement', 'status' => 'active', 'recorded_by' => 'api_key:2',
        'created_at' => '2026-10-16T09:45:00+03:00', 'voided_at' => null, 'voided_by' => null, 'void_reason' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function floatRedemptionPayload(array $overrides = []): array
{
    return [
        'id' => 4, 'bet_shop_id' => 3, 'amount' => 3000.00, 'payment_reference' => 'UJK8B2C001', 'note' => 'Owner asked for float back',
        'status' => 'active', 'recorded_by' => 'api_key:2', 'created_at' => '2026-10-16T15:00:00+03:00',
        'voided_at' => null, 'voided_by' => null, 'void_reason' => null,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function floatPage(array $items): array
{
    return ['success' => true, 'data' => ['items' => $items, 'pagination' => ['page' => 1, 'per_page' => 10, 'total' => count($items), 'last_page' => 1]]];
}

/**
 * Fake the unmatched queue (answered by the tab's `status`) and the open shops. `$stubs`
 * answer the writes and go first.
 *
 * @param  array<string, mixed>  $stubs
 */
function fakeShopFloatQueueApi(array $stubs = []): void
{
    Http::preventStrayRequests();

    Http::fake($stubs + [
        '*/deposits/unmatched*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $items = ($query['status'] ?? 'unmatched') === 'shop_float' ? [creditedShopFloatDeposit()] : [shopFloatDeposit()];

            return Http::response(['success' => true, 'data' => [
                'summary' => ['unmatched_count' => 1, 'unmatched_amount' => 10000.0],
                'items' => $items,
                'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1],
            ]]);
        },
        '*/bet-shops*' => Http::response(['success' => true, 'data' => [
            'items' => [
                ['id' => 3, 'shop_no' => 'S101', 'name' => 'Kariobangi Bet Shop', 'status' => 'active'],
                ['id' => 4, 'shop_no' => 'S102', 'name' => 'Umoja Bet Shop', 'status' => 'suspended'],
                ['id' => 5, 'shop_no' => 'S103', 'name' => 'Old Town Bet Shop', 'status' => 'closed'],
            ],
            'pagination' => ['page' => 1, 'per_page' => 200, 'total' => 3, 'last_page' => 1],
        ]]),
    ]);
}

/**
 * Fake shop 3's page: the shop (holding `$float`), its float tabs, and the given write stubs.
 *
 * @param  array<string, mixed>  $stubs
 */
function fakeShopFloatPageApi(float $float = 12500.00, array $stubs = []): void
{
    Http::preventStrayRequests();

    Http::fake($stubs + [
        '*/bet-shops/*/float-purchases*' => Http::response(floatPage([floatPurchasePayload()])),
        '*/bet-shops/*/float-redemptions*' => Http::response(floatPage([floatRedemptionPayload()])),
        '*/bet-shops/*' => Http::response(['success' => true, 'data' => [
            'id' => 3, 'shop_no' => 'S101', 'float_bill_ref' => 'SHOP-S101', 'name' => 'Kariobangi Bet Shop', 'owner_name' => 'Peter Mwangi',
            'owner_phone' => '254712345678', 'location' => null, 'status' => 'active', 'status_reason' => null, 'notes' => null,
            'float_balance' => $float, 'active_keys' => 1, 'created_at' => '2026-10-16T09:00:00+03:00', 'updated_at' => '2026-10-16T09:00:00+03:00',
        ]]),
    ]);
}

function isShopFloatWrite(Request $request, string $suffix): bool
{
    return $request->method() === 'POST' && str_ends_with($request->url(), $suffix);
}

function shopFloatAuditLog(string $subject, string $event): AuditLog
{
    return AuditLog::query()->where('auditable_type', 'KadiApi\\'.$subject)->where('event', $event)->sole();
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

    $this->admin = User::factory()->create(['name' => 'Grace Admin', 'status' => UserStatus::Active->value]);
    $this->admin->assignRole('admin');

    $this->manager = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->manager->assignRole('manager');
});

it('badges an owner\'s float payment and pre-fills its shop', function (): void {
    fakeShopFloatQueueApi();
    $this->actingAs($this->admin);

    Livewire::test(UnmatchedDepositsPage::class)
        ->assertSee('Shop float · S101')
        ->mountAction(TestAction::make('creditShopFloat')->table('5531'))
        ->assertMountedActionModalSee(UnmatchedDepositsPage::SHOP_FLOAT_WARNING)
        ->assertSchemaStateSet(['shop_no' => 'S101'], 'mountedActionSchema0');
});

it('credits the payment to the chosen shop with a signed note and an idempotency key, and logs it', function (): void {
    fakeShopFloatQueueApi([
        '*/deposits/*/shop-float' => Http::response(['success' => true, 'message' => 'Deposit credited to the shop float', 'data' => [
            'float_purchase' => floatPurchasePayload(['bet_shop_id' => 4, 'bill_ref_no' => 'SHOP-S101']),
            'deposit' => ['id' => 5531, 'status' => 2, 'resolution' => ['action' => 'shop_float', 'float_purchase_id' => 12]],
        ]]),
    ]);
    $this->actingAs($this->admin);

    // The owner mistyped the bill ref: the payment was for S102.
    Livewire::test(UnmatchedDepositsPage::class)
        ->callAction(TestAction::make('creditShopFloat')->table('5531'), data: ['shop_no' => 'S102', 'note' => 'Checked M-Pesa statement'])
        ->assertHasNoActionErrors()
        ->assertNotified(Notification::make()->title('Shop float credited')->body('KES 10,000.00 added to S102\'s float.')->success());

    Http::assertSent(fn (Request $request): bool => isShopFloatWrite($request, '/shop-float')
        && ! str_contains($request->url(), '/deposits/5531/')
        && $request->data() === ['shop_no' => 'S102', 'note' => 'Checked M-Pesa statement — by Grace Admin']
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));

    expect(shopFloatAuditLog('Deposit', 'shop_float')->auditable_id)->toBe(5531)
        ->and(shopFloatAuditLog('ShopFloatPurchase', 'credited')->auditable_id)->toBe(12)
        ->and(shopFloatAuditLog('ShopFloatPurchase', 'credited')->user_id)->toBe($this->admin->id);
});

it('does not offer closed shops for crediting', function (): void {
    fakeShopFloatQueueApi();
    $this->actingAs($this->admin);

    Livewire::test(UnmatchedDepositsPage::class)
        ->callAction(TestAction::make('creditShopFloat')->table('5531'), data: ['shop_no' => 'S103', 'note' => 'Checked M-Pesa statement'])
        ->assertHasActionErrors(['shop_no']);

    Http::assertNotSent(fn (Request $request): bool => isShopFloatWrite($request, '/shop-float'));
});

it('puts KadiApi\'s unknown-shop error on the shop field and retries with a new idempotency key', function (): void {
    fakeShopFloatQueueApi([
        '*/deposits/*/shop-float' => Http::sequence()
            ->push(['message' => 'No bet shop has that number.', 'errors' => ['shop_no' => ['No bet shop has that number.']]], 422)
            ->push(['success' => true, 'data' => ['float_purchase' => floatPurchasePayload(), 'deposit' => ['id' => 5531]]]),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(UnmatchedDepositsPage::class)
        ->mountAction(TestAction::make('creditShopFloat')->table('5531'))
        ->fillForm(['shop_no' => 'S102', 'note' => 'Checked M-Pesa statement'])
        ->callMountedAction()
        ->assertHasActionErrors(['shop_no' => 'No bet shop has that number.'])
        ->fillForm(['shop_no' => 'S101'])
        ->callMountedAction()
        ->assertNotified('Shop float credited');

    $keys = Http::recorded(fn (Request $request): bool => isShopFloatWrite($request, '/shop-float'))
        ->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])
        ->unique();

    expect($keys)->toHaveCount(2)
        ->and(shopFloatAuditLog('Deposit', 'shop_float_failed')->new_values['status'])->toBe(422);
});

it('shows KadiApi\'s refusal and closes the modal when the payment cannot be credited', function (int $status, string $code, string $message, string $title): void {
    fakeShopFloatQueueApi(['*/deposits/*/shop-float' => Http::response(['success' => false, 'code' => $code, 'message' => $message], $status)]);
    $this->actingAs($this->admin);

    Livewire::test(UnmatchedDepositsPage::class)
        ->callAction(TestAction::make('creditShopFloat')->table('5531'), data: ['shop_no' => 'S101', 'note' => 'Checked M-Pesa statement'])
        ->assertNotified(Notification::make()->title($title)->body($message)->warning())
        ->assertActionNotMounted(TestAction::make('creditShopFloat')->table('5531'));

    expect(shopFloatAuditLog('Deposit', 'shop_float_failed')->new_values['response']['code'])->toBe($code);
})->with([
    'already resolved' => [409, 'already_resolved', 'This deposit is no longer unmatched.', 'Deposit already resolved'],
    'shop closed' => [409, 'shop_closed', 'This shop is closed.', 'The shop is closed'],
    'deposit gone' => [404, 'deposit_not_found', 'Deposit not found', 'Deposit not found'],
]);

it('shows which shop a resolved payment went to and voids it from the row', function (): void {
    fakeShopFloatQueueApi([
        '*/bet-shops/float-purchases/*/void' => Http::response(['success' => true, 'message' => 'Float purchase voided; the deposit is unmatched again', 'data' => [
            'float_purchase' => floatPurchasePayload(['status' => 'voided', 'void_reason' => 'Wrong shop']),
        ]]),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(UnmatchedDepositsPage::class)
        ->call('setTab', 'shop_float')
        ->assertSee('S101 · Kariobangi Bet Shop')
        ->assertSeeHtml(e(BetShopDetailPage::getUrl(['shop' => 3])))
        ->callAction(TestAction::make('voidFloatPurchase')->table('5531'), data: ['reason' => 'Wrong shop'])
        ->assertHasNoActionErrors()
        ->assertNotified(Notification::make()->title('Float purchase voided')->body('KES 10,000.00 was taken off S101\'s float. The deposit is back in the unmatched queue.')->success());

    Http::assertSent(fn (Request $request): bool => isShopFloatWrite($request, '/void')
        && str_contains($request->url(), '/bet-shops/float-purchases/')
        && ! str_contains($request->url(), '/float-purchases/12/')
        && $request->data() === ['reason' => 'Wrong shop']
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));

    expect(shopFloatAuditLog('ShopFloatPurchase', 'voided')->auditable_id)->toBe(12);
});

it('shows what the float holds when a void is refused because the float was spent', function (): void {
    fakeShopFloatQueueApi([
        '*/bet-shops/float-purchases/*/void' => Http::response([
            'success' => false, 'code' => 'insufficient_float', 'message' => 'The shop float no longer holds this amount, so it cannot be reversed',
            'float_balance' => 4000.00, 'data' => ['float_purchase' => floatPurchasePayload()],
        ], 409),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(UnmatchedDepositsPage::class)
        ->call('setTab', 'shop_float')
        ->callAction(TestAction::make('voidFloatPurchase')->table('5531'), data: ['reason' => 'Wrong shop'])
        ->assertNotified(Notification::make()
            ->title('The float no longer holds this amount')
            ->body('The shop float no longer holds this amount, so it cannot be reversed The float holds KES 4,000.00.')
            ->warning());

    expect(shopFloatAuditLog('ShopFloatPurchase', 'void_failed')->new_values['response']['float_balance'])->toBe(4000);
});

it('lets managers see shop float payments but not credit or void them', function (): void {
    fakeShopFloatQueueApi();
    $this->actingAs($this->manager);

    Livewire::test(UnmatchedDepositsPage::class)
        ->assertSee('Shop float · S101')
        ->assertActionHidden(TestAction::make('creditShopFloat')->table('5531'))
        ->call('setTab', 'shop_float')
        ->assertActionHidden(TestAction::make('voidFloatPurchase')->table('5531'));
});

it('records a redemption after the owner was paid, with an idempotency key, and logs it', function (): void {
    fakeShopFloatPageApi(stubs: [
        '*/bet-shops/*/float-redemptions' => Http::response(['success' => true, 'message' => 'Float redemption recorded', 'float_balance' => 9500.00, 'data' => floatRedemptionPayload()], 201),
    ]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->mountAction('recordRedemption')
        ->assertMountedActionModalSee(BetShopDetailPage::REDEMPTION_WARNING)
        ->fillForm(['amount' => '3000', 'payment_reference' => ' ujk8b2c001 ', 'note' => 'Owner asked for float back', 'confirm_paid' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified(Notification::make()->title('Redemption of KES 3,000.00 recorded')->body('The float now holds KES 9,500.00.')->success())
        ->assertDispatched(ShopFloatPurchasesTable::REFRESH_EVENT);

    Http::assertSent(fn (Request $request): bool => isShopFloatWrite($request, '/float-redemptions')
        && array_keys($request->data()) === ['amount', 'payment_reference', 'note']
        && (float) $request['amount'] === 3000.0
        && $request['payment_reference'] === 'UJK8B2C001'
        && $request['note'] === 'Owner asked for float back'
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));

    $log = shopFloatAuditLog('ShopFloatRedemption', 'recorded');
    expect($log->auditable_id)->toBe(4)
        ->and($log->new_values['payload']['payment_reference'])->toBe('UJK8B2C001')
        ->and($log->new_values['response']['float_balance'])->toBe(9500);
});

it('refuses to record a redemption until the owner is confirmed paid', function (): void {
    fakeShopFloatPageApi();
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->callAction('recordRedemption', data: ['amount' => '3000', 'payment_reference' => 'UJK8B2C001', 'note' => 'Owner asked for float back', 'confirm_paid' => false])
        ->assertHasActionErrors(['confirm_paid' => 'Record a redemption only after the owner has been paid.']);

    Http::assertNotSent(fn (Request $request): bool => isShopFloatWrite($request, '/float-redemptions'));
});

it('puts KadiApi\'s insufficient float refusal on the amount and retries with a new idempotency key', function (): void {
    fakeShopFloatPageApi(stubs: [
        '*/bet-shops/*/float-redemptions' => Http::sequence()
            ->push(['success' => false, 'code' => 'insufficient_float', 'message' => 'The shop float does not hold this amount', 'float_balance' => 2500.00], 409)
            ->push(['success' => true, 'float_balance' => 0.00, 'data' => floatRedemptionPayload(['amount' => 2500.00])], 201),
    ]);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->mountAction('recordRedemption')
        ->fillForm(['amount' => '3000', 'payment_reference' => 'UJK8B2C001', 'note' => 'Owner asked for float back', 'confirm_paid' => true])
        ->callMountedAction()
        ->assertHasActionErrors(['amount' => 'The shop float does not hold this amount The float holds KES 2,500.00.'])
        ->assertActionMounted('recordRedemption')
        ->fillForm(['amount' => '2500'])
        ->callMountedAction()
        ->assertNotified('Redemption of KES 2,500.00 recorded');

    $keys = Http::recorded(fn (Request $request): bool => isShopFloatWrite($request, '/float-redemptions'))
        ->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])
        ->unique();

    expect($keys)->toHaveCount(2);
});

it('disables recording a redemption while the float is empty', function (): void {
    fakeShopFloatPageApi(float: 0.00);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->assertActionDisabled('recordRedemption');
});

it('lists the shop\'s float purchases and voids one, refreshing the shop page', function (): void {
    fakeShopFloatPageApi(stubs: [
        '*/bet-shops/float-purchases/*/void' => Http::response(['success' => true, 'data' => ['float_purchase' => floatPurchasePayload(['status' => 'voided'])]]),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ShopFloatPurchasesTable::class, ['shopId' => 3, 'shopNo' => 'S101'])
        ->assertSee('UJK8H2L1QX')
        ->assertSee('KES 10,000.00')
        ->assertSee('GMS (API key #2)')
        ->callAction(TestAction::make('voidFloatPurchase')->table('12'), data: ['reason' => 'Wrong shop'])
        ->assertNotified('Float purchase voided')
        ->assertDispatched(BetShopDetailPage::REFRESH_EVENT);

    Http::assertSent(fn (Request $request): bool => isShopFloatWrite($request, '/void') && ! str_contains($request->url(), '/float-purchases/12/'));
});

it('lists the shop\'s redemptions and voids one, putting the amount back on the float', function (): void {
    fakeShopFloatPageApi(stubs: [
        '*/bet-shops/float-redemptions/*/void' => Http::response(['success' => true, 'message' => 'Float redemption voided; the amount is back on the float', 'data' => floatRedemptionPayload(['status' => 'voided'])]),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ShopFloatRedemptionsTable::class, ['shopId' => 3])
        ->assertSee('UJK8B2C001')
        ->assertSee('KES 3,000.00')
        ->callAction(TestAction::make('voidRedemption')->table('4'), data: ['reason' => 'The bank transfer was returned'])
        ->assertNotified(Notification::make()->title('Redemption voided')->body('KES 3,000.00 is back on the shop\'s float.')->success())
        ->assertDispatched(BetShopDetailPage::REFRESH_EVENT);

    Http::assertSent(fn (Request $request): bool => isShopFloatWrite($request, '/void')
        && str_contains($request->url(), '/bet-shops/float-redemptions/')
        && ! str_contains($request->url(), '/float-redemptions/4/')
        && $request->data() === ['reason' => 'The bank transfer was returned']
        && Str::isUuid($request->header('Idempotency-Key')[0] ?? ''));

    expect(shopFloatAuditLog('ShopFloatRedemption', 'voided')->auditable_id)->toBe(4);
});

it('shows KadiApi\'s message when a redemption is already voided', function (): void {
    fakeShopFloatPageApi(stubs: [
        '*/bet-shops/float-redemptions/*/void' => Http::response(['success' => false, 'code' => 'already_resolved', 'message' => 'This redemption is already voided.'], 409),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ShopFloatRedemptionsTable::class, ['shopId' => 3])
        ->callAction(TestAction::make('voidRedemption')->table('4'), data: ['reason' => 'Recorded twice'])
        ->assertNotified(Notification::make()->title('Redemption already voided')->body('This redemption is already voided.')->warning());
});

it('lets managers see a shop\'s float but not record or void anything', function (): void {
    fakeShopFloatPageApi();
    $this->actingAs($this->manager);

    Livewire::withQueryParams(['shop' => 3])
        ->test(BetShopDetailPage::class)
        ->assertActionHidden('recordRedemption');

    Livewire::test(ShopFloatPurchasesTable::class, ['shopId' => 3, 'shopNo' => 'S101'])
        ->assertSee('UJK8H2L1QX')
        ->assertActionHidden(TestAction::make('voidFloatPurchase')->table('12'));

    Livewire::test(ShopFloatRedemptionsTable::class, ['shopId' => 3])
        ->assertSee('UJK8B2C001')
        ->assertActionHidden(TestAction::make('voidRedemption')->table('4'));
});
