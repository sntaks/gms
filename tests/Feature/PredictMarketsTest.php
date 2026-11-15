<?php

use App\Filament\Pages\PredictMarketDetailPage;
use App\Filament\Pages\PredictMarketsPage;
use App\Models\AuditLog;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

const PREDICT = 'https://game-api.test/predict/admin/markets';

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    config(['services.game_api.url' => 'https://game-api.test', 'services.game_api.key' => 'test-api-key']);
    Http::preventStrayRequests();
    travelTo('2026-11-16 09:00:00');
});

/**
 * A market as the Kadi API's admin resource returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function predictMarket(array $overrides = []): array
{
    return [
        'id' => 42, 'source' => 'custom', 'question' => 'Will Harambee Stars qualify for AFCON 2027?',
        'description' => null, 'resolution_criteria' => 'Yes if CAF lists Kenya among the qualified teams by 30 Jun 2027.',
        'resolution_source' => 'cafonline.com', 'category' => 'sport', 'countries' => ['KE'], 'status' => 'open', 'engine' => 'pool',
        'close_at' => '2027-06-01T12:00:00+03:00', 'resolution_deadline_at' => '2027-07-01T12:00:00+03:00', 'betting_closes_at' => '2027-06-01T12:00:00+03:00',
        'result' => null, 'min_stake' => 10.0, 'max_stake' => 5000.0, 'max_payout' => 250000.0,
        'odds' => ['yes_label' => 'Yes', 'no_label' => 'No', 'yes_probability' => 0.62, 'no_probability' => 0.38, 'yes_multiplier' => 1.49, 'no_multiplier' => 2.31, 'is_estimate' => true, 'volume' => 12500.0, 'bets_count' => 37],
        'cut' => 0.2, 'seed' => 5000.0, 'start_probability' => 0.6, 'min_multiplier' => 1.01, 'margin' => 0.05, 'liquidity' => null,
        'worst_case_loss' => null, 'max_liability' => 500000.0, 'max_customer_stake' => 20000.0, 'exposure' => null,
        'pool' => ['yes' => 10850.0, 'no' => 6650.0, 'seed_yes' => 3000.0, 'seed_no' => 2000.0], 'external' => null, 'external_url' => null,
        'created_by' => 'Jane Admin (GMS #3)', 'resolved_by' => null, 'reject_reason' => null, 'void_reason' => null,
        ...$overrides,
    ];
}

function marketList(array $items): array
{
    return ['success' => true, 'data' => ['items' => $items, 'pagination' => ['page' => 1, 'per_page' => 50, 'total' => count($items), 'last_page' => 1]]];
}

function predictAudit(): AuditLog
{
    return AuditLog::query()->where('auditable_type', 'KadiApi\\PredictMarket')->latest('id')->firstOrFail();
}

it('opens the markets to finance, managers and admins only', function (string $role, bool $allowed): void {
    signInWithRole($role);
    Http::fake([PREDICT.'*' => Http::response(marketList([]))]);

    $response = get(PredictMarketsPage::getUrl());

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'finance' => ['finance', true],
    'manager' => ['manager', true],
    'admin' => ['admin', true],
    'trader' => ['trader', false],
    'agent' => ['agent', false],
]);

it('lists drafts first with their estimated odds', function (): void {
    signInWithRole('finance');
    Http::fake([PREDICT.'*' => Http::response(marketList([predictMarket(['status' => 'draft'])]))]);

    Livewire::test(PredictMarketsPage::class)
        ->assertSee('Will Harambee Stars qualify for AFCON 2027?')
        ->assertSee('Yes 1.49x · No 2.31x');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), PREDICT.'?') && $request['status'] === 'draft');
});

it('drafts a market in the Kadi API\'s units and names the admin', function (): void {
    $admin = signInWithRole('admin', ['name' => 'Jane Admin']);
    Http::fake([
        PREDICT.'?*' => Http::response(marketList([])),
        PREDICT => Http::response(['success' => true, 'data' => predictMarket(['id' => 77, 'status' => 'draft'])], 201),
    ]);

    Livewire::test(PredictMarketsPage::class)
        ->callAction('createMarket', data: [
            'engine' => 'pool',
            'question' => 'Will Harambee Stars qualify for AFCON 2027?',
            'resolution_criteria' => 'Yes if CAF lists Kenya among the qualified teams by 30 Jun 2027.',
            'category' => 'sport',
            'countries' => ['KE'],
            'close_at' => '2027-06-01 12:00:00',
            'resolution_deadline_at' => '2027-07-01 12:00:00',
            'start_probability_percent' => '60',
            'cut_percent' => '20',
            'seed' => '',
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect(PredictMarketDetailPage::getUrl(['market' => 77]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['admin_user'] === "Jane Admin (GMS #{$admin->id})"
        && $request['start_probability'] === 0.6
        && $request['cut'] === 0.2
        && $request['close_at'] === '2027-06-01T12:00:00+03:00'
        && ! isset($request['seed'])
        && ! isset($request['min_stake']));
    expect(predictAudit()->event)->toBe('create');
});

it('puts the Kadi API\'s validation message on the form', function (): void {
    signInWithRole('admin');
    Http::fake([PREDICT.'?*' => Http::response(marketList([])), PREDICT => Http::response(['success' => false, 'message' => 'The question has already been asked.', 'code' => 'validation_failed', 'errors' => ['question' => ['The question has already been asked.']]], 422)]);

    Livewire::test(PredictMarketsPage::class)
        ->callAction('createMarket', data: [
            'engine' => 'pool', 'question' => 'Duplicate?', 'resolution_criteria' => 'Yes if so.', 'category' => 'sport',
            'countries' => ['KE'], 'close_at' => '2027-06-01 12:00:00', 'resolution_deadline_at' => '2027-07-01 12:00:00', 'start_probability_percent' => '50',
        ])
        ->assertHasActionErrors(['question' => 'The question has already been asked.']);
});

it('shows why a draft could not be published', function (): void {
    signInWithRole('admin');
    Http::fake([
        PREDICT.'/42/publish' => Http::response(['success' => false, 'message' => 'This market has no usable Polymarket price to open the pool at', 'code' => 'price_unavailable', 'retryable' => false], 409),
        PREDICT.'*' => Http::response(marketList([predictMarket(['status' => 'draft', 'source' => 'polymarket'])])),
    ]);

    Livewire::test(PredictMarketsPage::class)
        ->callAction(TestAction::make('publishMarket')->table('42'))
        ->assertNotified(Notification::make()->title('Could not publish')->body('This market has no usable Polymarket price to open the pool at')->warning());

    expect(predictAudit()->new_values['response']['code'])->toBe('price_unavailable');
});

it('publishes every matching draft by walking the cursor past refused ones', function (): void {
    signInWithRole('admin');
    Http::fake([
        PREDICT.'/bulk/publish' => Http::sequence()
            ->push(['success' => true, 'data' => ['done' => range(1, 480), 'refused' => array_map(fn (int $id): array => ['id' => $id, 'code' => 'price_unavailable'], range(481, 500)), 'remaining' => 147, 'next_after_id' => 500]])
            ->push(['success' => true, 'data' => ['done' => range(501, 640), 'refused' => [['id' => 641, 'code' => 'price_unavailable'], ['id' => 642, 'code' => 'close_time_passed']], 'remaining' => 22, 'next_after_id' => null]]),
        PREDICT.'*' => Http::response(marketList([])),
    ]);

    Livewire::test(PredictMarketsPage::class)
        ->callAction('bulkPublish', data: ['source' => 'polymarket', 'min_volume' => '100000'])
        ->assertNotified(Notification::make()->title('Published 620 drafts')->body('Refused and left as drafts: price unavailable ×21, close time passed ×1.')->success()->persistent());

    $bulk = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])->filter(fn (Request $request): bool => str_contains($request->url(), '/bulk/'))->values();
    expect($bulk)->toHaveCount(2)
        ->and($bulk[0]['filter'])->toBe(['source' => 'polymarket', 'min_volume' => 100000.0])
        ->and($bulk[0]->data())->not->toHaveKey('after_id')
        ->and($bulk[1]['after_id'])->toBe(500);
});

it('stops a bulk run without resending when the Kadi API does not answer', function (): void {
    signInWithRole('admin');
    Http::fake([
        PREDICT.'/bulk/reject' => Http::failedConnection(),
        PREDICT.'*' => Http::response(marketList([])),
    ]);

    Livewire::test(PredictMarketsPage::class)
        ->callAction('bulkReject', data: ['source' => 'polymarket', 'max_volume' => '5000', 'reason' => 'Too little volume'])
        ->assertNotified(Notification::make()->title('Rejected 0 drafts')->body('Stopped: '.PredictMarketsPage::UNKNOWN_OUTCOME)->warning()->persistent());

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/bulk/')))->toHaveCount(1);
});

it('resolves a closed market for one side and pays out', function (): void {
    $finance = signInWithRole('finance', ['name' => 'Fatuma Finance']);
    Http::fake([
        PREDICT.'/42/resolve' => Http::response(['success' => true, 'data' => predictMarket(['status' => 'resolved', 'result' => 'yes'])]),
        PREDICT.'/42' => Http::response(['success' => true, 'data' => predictMarket(['status' => 'closed'])]),
    ]);

    Livewire::test(PredictMarketDetailPage::class, ['market' => 42])
        ->callAction('resolveMarket', data: ['result' => 'yes'])
        ->assertHasNoActionErrors()
        ->assertNotified('Resolved: Yes');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/42/resolve')
        && $request->data() === ['admin_user' => "Fatuma Finance (GMS #{$finance->id})", 'result' => 'yes']);
});

it('never resends a resolve whose answer was lost, and reloads the market instead', function (): void {
    signInWithRole('finance');
    Http::fake([
        PREDICT.'/42/resolve' => Http::failedConnection(),
        PREDICT.'/42' => Http::sequence()
            ->push(['success' => true, 'data' => predictMarket(['status' => 'closed'])])
            ->push(['success' => true, 'data' => predictMarket(['status' => 'resolved', 'result' => 'no'])]),
    ]);

    Livewire::test(PredictMarketDetailPage::class, ['market' => 42])
        ->callAction('resolveMarket', data: ['result' => 'no'])
        ->assertNotified('Not confirmed')
        ->assertActionHidden('resolveMarket');

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/resolve')))->toHaveCount(1);
});

it('offers each lifecycle step only in its status and to its role', function (string $role, string $status, array $visible, array $hidden): void {
    signInWithRole($role);
    Http::fake([PREDICT.'/42' => Http::response(['success' => true, 'data' => predictMarket(['status' => $status])])]);

    $page = Livewire::test(PredictMarketDetailPage::class, ['market' => 42]);

    foreach ($visible as $action) {
        $page->assertActionVisible($action);
    }

    foreach ($hidden as $action) {
        $page->assertActionHidden($action);
    }
})->with([
    'admin, draft' => ['admin', 'draft', ['editMarket', 'publishMarket', 'rejectMarket', 'voidMarket'], ['suspendMarket', 'resolveMarket']],
    'admin, open' => ['admin', 'open', ['editMarket', 'suspendMarket', 'closeMarket', 'voidMarket'], ['publishMarket', 'resumeMarket', 'resolveMarket']],
    'finance, open' => ['finance', 'open', ['voidMarket'], ['editMarket', 'suspendMarket', 'closeMarket']],
    'finance, closed' => ['finance', 'closed', ['resolveMarket', 'voidMarket'], ['editMarket', 'closeMarket']],
    'manager, closed' => ['manager', 'closed', [], ['resolveMarket', 'voidMarket', 'editMarket']],
    'admin, settled' => ['admin', 'settled', [], ['resolveMarket', 'voidMarket', 'editMarket']],
]);

it('sends only what may change on a live market', function (): void {
    signInWithRole('admin');
    Http::fake([
        PREDICT.'/42' => Http::sequence()
            ->push(['success' => true, 'data' => predictMarket()])
            ->push(['success' => true, 'data' => predictMarket(['question' => 'Will Kenya qualify for AFCON 2027?'])])
            ->push(['success' => true, 'data' => predictMarket(['question' => 'Will Kenya qualify for AFCON 2027?'])]),
    ]);

    Livewire::test(PredictMarketDetailPage::class, ['market' => 42])
        ->mountAction('editMarket')
        ->setActionData(['question' => 'Will Kenya qualify for AFCON 2027?'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Market saved');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request['question'] === 'Will Kenya qualify for AFCON 2027?'
        && $request['close_at'] === '2027-06-01T12:00:00+03:00'
        && ! isset($request['resolution_criteria'])
        && ! isset($request['cut'])
        && ! isset($request['start_probability']));
});

it('needs a reason to void', function (): void {
    signInWithRole('finance');
    Http::fake([PREDICT.'/42' => Http::response(['success' => true, 'data' => predictMarket()])]);

    Livewire::test(PredictMarketDetailPage::class, ['market' => 42])
        ->callAction('voidMarket', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/void'));
});

it('shows the market\'s odds, pool and terms', function (): void {
    signInWithRole('manager');
    Http::fake([PREDICT.'/42' => Http::response(['success' => true, 'data' => predictMarket()])]);

    get(PredictMarketDetailPage::getUrl(['market' => 42]))
        ->assertSee('62% · 1.49x (estimated)')
        ->assertSee('Yes KES 10,850.00 · No KES 6,650.00 (seed KES 5,000.00, notional)')
        ->assertSee('Cut 20% · Min multiplier 1.01x · Opened at 60% Yes')
        ->assertSee('KES 12,500.00 on 37 bets');
});
