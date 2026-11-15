<?php

use App\Filament\Pages\PredictAuditPage;
use App\Filament\Pages\PredictCorrectionsPage;
use App\Filament\Pages\PredictMarketDetailPage;
use App\Filament\Pages\PredictProposalsPage;
use App\Filament\Pages\PredictReportPage;
use App\Filament\Pages\PredictRiskEventsPage;
use App\Models\AuditLog;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;

const PREDICT_ADMIN = 'https://game-api.test/predict/admin';

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    config(['services.game_api.url' => 'https://game-api.test', 'services.game_api.key' => 'test-api-key']);
    Http::preventStrayRequests();
});

/**
 * A settled market with two settlement runs, the second of which failed.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function settledMarket(array $overrides = []): array
{
    return [
        'id' => 42, 'source' => 'custom', 'question' => 'Will Harambee Stars qualify for AFCON 2027?', 'status' => 'settled', 'engine' => 'pool',
        'result' => 'yes', 'category' => 'sport', 'countries' => ['KE'], 'resolution_criteria' => 'Yes if CAF lists Kenya.',
        'odds' => ['yes_label' => 'Yes', 'no_label' => 'No'], 'min_stake' => 10.0, 'max_stake' => 5000.0, 'max_payout' => 250000.0,
        'settlements' => [
            ['id' => 7, 'run_no' => 1, 'kind' => 'settle', 'result' => 'yes', 'status' => 'done', 'winners_count' => 30, 'payouts' => 9100.0, 'house_net' => 3400.0, 'final_multiplier' => 1.49, 'error' => null],
            ['id' => 8, 'run_no' => 2, 'kind' => 'settle', 'result' => 'yes', 'status' => 'failed', 'winners_count' => 0, 'payouts' => 0.0, 'house_net' => 0.0, 'final_multiplier' => null, 'error' => 'wallet_timeout'],
        ],
        ...$overrides,
    ];
}

function pagedItems(array $items): array
{
    return ['success' => true, 'data' => ['items' => $items, 'pagination' => ['page' => 1, 'per_page' => 50, 'total' => count($items), 'last_page' => 1]]];
}

function fakeMarketPage(array $market, array $more = []): void
{
    Http::fake([
        ...$more,
        PREDICT_ADMIN.'/markets/42/blocks' => Http::response(['success' => true, 'data' => [['customer_id' => 5501, 'reason' => 'Knows the result', 'admin_user' => 'Jane Admin (GMS #3)']]]),
        PREDICT_ADMIN.'/markets/42' => Http::response(['success' => true, 'data' => $market]),
    ]);
}

it('shows settlement runs and retries the failed one', function (): void {
    signInWithRole('finance');
    fakeMarketPage(settledMarket(), [
        PREDICT_ADMIN.'/settlements/8/retry' => Http::response(['success' => true, 'data' => ['settlement' => ['id' => 8, 'status' => 'running'], 'replayed' => false]]),
    ]);

    Livewire::test(PredictMarketDetailPage::class, ['market' => 42])
        ->assertSee('#2 settle Yes: failed')
        ->assertSee('error: wallet_timeout')
        ->callAction('retrySettlement')
        ->assertNotified('Settlement queued again');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/settlements/8/retry') && isset($request['admin_user']));
});

it('requests a correction to the other side of a settled market', function (): void {
    $finance = signInWithRole('finance', ['name' => 'Fatuma Finance']);
    fakeMarketPage(settledMarket(), [
        PREDICT_ADMIN.'/markets/42/corrections' => Http::response(['success' => true, 'data' => ['correction' => ['id' => 3, 'status' => 'requested'], 'replayed' => false]], 201),
    ]);

    Livewire::test(PredictMarketDetailPage::class, ['market' => 42])
        ->mountAction('requestCorrection')
        ->assertActionDataSet(['result' => 'no'])
        ->setActionData(['reason' => 'CAF corrected its list'])
        ->callMountedAction()
        ->assertNotified('Correction requested');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/markets/42/corrections')
        && $request->data() === ['admin_user' => "Fatuma Finance (GMS #{$finance->id})", 'result' => 'no', 'reason' => 'CAF corrected its list']);
});

it('blocks and unblocks a player on a market', function (): void {
    signInWithRole('admin');
    fakeMarketPage(settledMarket(['status' => 'open', 'settlements' => []]), [
        PREDICT_ADMIN.'/markets/42/blocks/5501' => Http::response(['success' => true, 'data' => ['replayed' => false]]),
        PREDICT_ADMIN.'/markets/42/blocks' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['success' => true, 'data' => ['customer_id' => 6602, 'reason' => 'Insider', 'replayed' => false]], 201)
            : Http::response(['success' => true, 'data' => [['customer_id' => 5501, 'reason' => 'Knows the result', 'admin_user' => 'Jane Admin (GMS #3)']]]),
    ]);

    Livewire::test(PredictMarketDetailPage::class, ['market' => 42])
        ->assertSee('Customer #5501: Knows the result (by Jane Admin (GMS #3))')
        ->callAction('blockCustomer', data: ['customer_id' => 6602, 'reason' => 'Insider'])
        ->assertNotified('Customer #6602 blocked')
        ->callAction('unblockCustomer', data: ['customer_id' => 5501, 'reason' => 'Cleared'])
        ->assertNotified('Customer #5501 unblocked');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/blocks') && $request['customer_id'] === 6602);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/blocks/5501') && $request['reason'] === 'Cleared');
});

it('confirms a waiting Polymarket proposal and pays out', function (): void {
    signInWithRole('finance');
    Http::fake([
        PREDICT_ADMIN.'/proposals/11/confirm' => Http::response(['success' => true, 'data' => ['proposal' => ['id' => 11, 'status' => 'confirmed'], 'replayed' => false]]),
        PREDICT_ADMIN.'/proposals*' => Http::response(pagedItems([['id' => 11, 'market_id' => 42, 'question' => 'Will it rain in Nairobi on 1 Dec?', 'market_status' => 'closed', 'result' => 'yes', 'evidence' => ['source' => 'polymarket'], 'status' => 'pending']])),
    ]);

    Livewire::test(PredictProposalsPage::class)
        ->assertSee('Will it rain in Nairobi on 1 Dec?')
        ->callAction(TestAction::make('confirmProposal')->table('11'))
        ->assertNotified('Proposal confirmed');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/proposals?') && $request['status'] === 'pending');
    expect(AuditLog::query()->where('auditable_type', 'KadiApi\\PredictProposal')->sole()->event)->toBe('confirm');
});

it('never offers the requester the approval of their own correction', function (): void {
    $finance = signInWithRole('finance', ['name' => 'Fatuma Finance']);
    Http::fake([PREDICT_ADMIN.'/corrections*' => Http::response(pagedItems([
        ['id' => 3, 'market_id' => 42, 'from_result' => 'yes', 'to_result' => 'no', 'reason' => 'CAF corrected its list', 'status' => 'requested', 'requested_by' => "Fatuma Finance (GMS #{$finance->id})"],
        ['id' => 4, 'market_id' => 43, 'from_result' => 'no', 'to_result' => 'yes', 'reason' => 'Wrong side', 'status' => 'requested', 'requested_by' => 'Jane Admin (GMS #3)'],
    ]))]);

    Livewire::test(PredictCorrectionsPage::class)
        ->assertActionHidden(TestAction::make('approveCorrection')->table('3'))
        ->assertActionVisible(TestAction::make('cancelCorrection')->table('3'))
        ->assertActionVisible(TestAction::make('approveCorrection')->table('4'));
});

it('shows the Kadi API\'s refusal when the same admin tries to approve', function (): void {
    signInWithRole('admin');
    Http::fake([
        PREDICT_ADMIN.'/corrections/4/approve' => Http::response(['success' => false, 'message' => 'A different admin must approve the correction', 'code' => 'same_admin', 'retryable' => false], 403),
        PREDICT_ADMIN.'/corrections*' => Http::response(pagedItems([['id' => 4, 'market_id' => 43, 'from_result' => 'no', 'to_result' => 'yes', 'reason' => 'Wrong side', 'status' => 'requested', 'requested_by' => 'Someone (GMS #99)']])),
    ]);

    Livewire::test(PredictCorrectionsPage::class)
        ->callAction(TestAction::make('approveCorrection')->table('4'))
        ->assertNotified(Notification::make()->title('Could not approve the correction')->body('A different admin must approve the correction')->warning());
});

it('lists open risk flags and all of them, and closes one with what was found', function (): void {
    signInWithRole('finance');
    Http::fake([
        PREDICT_ADMIN.'/risk-events/21/resolve' => Http::response(['success' => true, 'data' => ['risk_event' => ['id' => 21, 'resolved_at' => '2026-11-16T10:00:00+03:00']]]),
        PREDICT_ADMIN.'/risk-events*' => Http::response(pagedItems([['id' => 21, 'type' => 'predict_side_cluster', 'severity' => 'medium', 'score' => 60, 'customer_id' => 5501, 'market_id' => 42, 'details' => ['market_id' => 42, 'side' => 'yes', 'customers' => 6], 'resolved_at' => null]])),
    ]);

    Livewire::test(PredictRiskEventsPage::class)
        ->assertSee('Many players piling onto one side')
        ->assertSee('side: yes · customers: 6')
        ->callAction(TestAction::make('resolveRiskEvent')->table('21'), data: ['reason' => 'A WhatsApp tip, not inside knowledge'])
        ->assertNotified('Flag closed')
        ->call('setTab', 'all');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && ! isset($request['resolved']));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && ($request['resolved'] ?? null) == 1);
});

it('shows one market\'s audit trail with what changed', function (): void {
    signInWithRole('manager');
    Http::fake([PREDICT_ADMIN.'/audit*' => Http::response(pagedItems([
        ['id' => 1, 'market_id' => 42, 'action' => 'suspend', 'admin_user' => 'Jane Admin (GMS #3)', 'reason' => null, 'before' => ['status' => 'open'], 'after' => ['status' => 'suspended']],
    ]))]);

    get(PredictAuditPage::getUrl(['market' => 42]))
        ->assertSee('status: open → suspended')
        ->assertSee('Jane Admin (GMS #3)');

    Http::assertSent(fn (Request $request): bool => $request['market_id'] == 42);
});

it('reports a settled market\'s house result', function (): void {
    signInWithRole('manager');
    Http::fake([PREDICT_ADMIN.'/reports/markets*' => Http::response(pagedItems([
        ['market_id' => 42, 'question' => 'Will Harambee Stars qualify for AFCON 2027?', 'status' => 'settled', 'result' => 'yes', 'bets' => 37, 'stakes' => 12500.0, 'seed' => 5000.0, 'payouts' => 9100.0, 'house_net' => 3400.0, 'cut' => 1330.0, 'seed_result' => 2070.0],
    ]))]);

    Livewire::test(PredictReportPage::class)
        ->assertSee('KES 3,400.00')
        ->assertSee('KES 2,070.00');
});

it('keeps traders and agents out of the prediction queues', function (string $role): void {
    signInWithRole($role);

    get(PredictCorrectionsPage::getUrl())->assertForbidden();
    get(PredictRiskEventsPage::getUrl())->assertForbidden();
})->with(['trader', 'agent']);
