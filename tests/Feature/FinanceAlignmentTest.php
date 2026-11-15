<?php

use App\Enums\PayoutStatus;
use App\Exceptions\GameApiException;
use App\Filament\Pages\ApiExpensesReport;
use App\Filament\Pages\DepositsPage;
use App\Filament\Pages\IncomeStatementReport;
use App\Filament\Pages\LedgerReport;
use App\Filament\Pages\UnmatchedDepositsPage;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Jobs\RecordPayoutExpenseJob;
use App\Models\Payee;
use App\Models\Payout;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

function expensesList(array $items = []): array
{
    return ['success' => true, 'data' => ['meta' => ['warnings' => []], 'summary' => ['active' => ['entries' => count($items), 'amount' => 0], 'voided' => ['entries' => 0, 'amount' => 0], 'by_category' => []], 'items' => $items, 'pagination' => ['page' => 1, 'per_page' => 50, 'total' => count($items), 'last_page' => 1]]];
}

/**
 * A payout M-Pesa has paid, made directly (the maker-checker flow is tested elsewhere).
 *
 * @param  array<string, mixed>  $attributes
 */
function paidPayout(array $attributes = []): Payout
{
    $payee = Payee::create(['name' => 'Achieng Otieno', 'phone' => '254712345678', 'designation' => 'Accountant', 'team' => 'Finance']);
    signInWithRole('super-admin');

    $payout = Payout::create(['payee_id' => $payee->id, 'amount' => 1500, 'reason' => 'Monthly bookkeeping fee']);
    $payout->forceFill(['status' => PayoutStatus::Completed, 'processed_at' => '2026-11-15 18:30:00', ...$attributes])->saveQuietly();

    return $payout->fresh();
}

it('records a company expense in the Kadi API ledger', function (): void {
    signInWithRole('finance');
    Http::fake([
        'https://game-api.test/finance/expenses' => Http::response(['success' => true, 'data' => ['id' => 55, 'status' => 'active']], 201),
        'https://game-api.test/finance/expenses?*' => Http::response(expensesList()),
    ]);

    Livewire::test(ApiExpensesReport::class)
        ->callAction('recordExpense', data: ['expense_date' => '2026-11-15', 'category' => 'hosting', 'amount' => 12000, 'description' => 'DigitalOcean November', 'reference' => 'INV-2026-11'])
        ->assertHasNoActionErrors()
        ->assertNotified('Expense recorded');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['category'] === 'hosting'
        && $request['reference'] === 'INV-2026-11'
        && $request->hasHeader('Idempotency-Key'));
});

it('puts a taken reference on the form', function (): void {
    signInWithRole('finance');
    Http::fake([
        'https://game-api.test/finance/expenses' => Http::response(['success' => false, 'message' => 'The reference has already been taken.', 'errors' => ['reference' => ['The reference has already been taken.']]], 422),
        'https://game-api.test/finance/expenses?*' => Http::response(expensesList()),
    ]);

    Livewire::test(ApiExpensesReport::class)
        ->callAction('recordExpense', data: ['expense_date' => '2026-11-15', 'category' => 'sms', 'amount' => 500, 'reference' => 'DUP-1'])
        ->assertHasActionErrors(['reference' => 'The reference has already been taken.']);
});

it('voids an expense entered in error', function (): void {
    signInWithRole('finance');
    Http::fake([
        'https://game-api.test/finance/expenses/*/void' => Http::response(['success' => true, 'data' => ['id' => 55, 'status' => 'voided']]),
        'https://game-api.test/finance/expenses?*' => Http::response(expensesList([['id' => 55, 'expense_date' => '2026-11-15', 'category' => 'hosting', 'amount' => 12000.0, 'status' => 'active']])),
    ]);

    Livewire::test(ApiExpensesReport::class)
        ->callAction(TestAction::make('voidExpense')->table('55'), data: ['reason' => 'Entered twice'])
        ->assertNotified('Expense voided');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/void') && $request['reason'] === 'Entered twice');
});

it('keeps local expenses as read-only history pointing to the API', function (): void {
    signInWithRole('admin');

    expect(ExpenseResource::canCreate())->toBeFalse();
    get(ExpenseResource::getUrl())->assertOk()->assertSee('Record an expense');
});

it('records a paid payout as a Kadi API expense once', function (): void {
    $payout = paidPayout();
    Http::fake(['https://game-api.test/finance/expenses' => Http::response(['success' => true, 'data' => ['id' => 81]], 201)]);

    RecordPayoutExpenseJob::dispatchSync($payout->id);
    RecordPayoutExpenseJob::dispatchSync($payout->id);

    expect($payout->fresh()->api_expense_id)->toBe(81);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'expense_date' => '2026-11-15',
        'category' => 'other',
        'amount' => 1500.0,
        'description' => 'Payout to Achieng Otieno (Accountant) — Monthly bookkeeping fee',
        'reference' => 'GMS-PAYOUT-'.$payout->id,
    ] && $request->header('Idempotency-Key')[0] === 'gms-payout-expense-'.$payout->id);
});

it('keeps the refusal on the payout and only retries outages', function (int $status, bool $rethrows): void {
    $payout = paidPayout();
    Http::fake(['https://game-api.test/finance/expenses' => Http::response(['success' => false, 'message' => 'Refused for a test'], $status)]);

    $run = fn () => RecordPayoutExpenseJob::dispatchSync($payout->id);

    $rethrows ? expect($run)->toThrow(GameApiException::class) : $run();
    expect($payout->fresh())
        ->api_expense_id->toBeNull()
        ->api_expense_error->toBe('Refused for a test');
})->with([
    'refused' => [422, false],
    'outage' => [503, true],
]);

it('never records a payout that was not paid', function (): void {
    $payout = paidPayout(['status' => PayoutStatus::Failed]);
    Http::fake();

    RecordPayoutExpenseJob::dispatchSync($payout->id);

    Http::assertNothingSent();
});

it('queues the expense when M-Pesa confirms a payout', function (int $resultCode, bool $queued): void {
    Queue::fake([RecordPayoutExpenseJob::class]);
    $payout = paidPayout(['status' => PayoutStatus::Processing, 'conversation_id' => 'AG_2026_ABC']);

    $this->postJson('/b2c/result', ['Result' => ['ConversationID' => 'AG_2026_ABC', 'ResultCode' => $resultCode, 'ResultDesc' => 'Done']])->assertOk();

    $queued
        ? Queue::assertPushed(RecordPayoutExpenseJob::class, fn (RecordPayoutExpenseJob $job): bool => $job->payoutId === $payout->id)
        : Queue::assertNotPushed(RecordPayoutExpenseJob::class);
})->with([
    'paid' => [0, true],
    'failed' => [2001, false],
]);

it('offers to record a paid payout the API has not recorded', function (): void {
    $payout = paidPayout(['api_expense_error' => 'Kadi API down']);

    Livewire::test(ListPayouts::class)
        ->assertActionVisible(TestAction::make('recordApiExpense')->table($payout));
});

it('filters deposits by house funding and shop float, and lists house-funded deposits', function (): void {
    signInWithRole('finance');
    Http::fake([
        'https://game-api.test/finance/deposits*' => Http::response(['success' => true, 'data' => ['meta' => ['warnings' => []], 'summary' => [], 'items' => [], 'pagination' => []]]),
        'https://game-api.test/deposits/unmatched*' => Http::response(['success' => true, 'data' => ['items' => [], 'pagination' => []]]),
    ]);

    Livewire::test(DepositsPage::class)->filterTable('kind', 'house_funding');
    Livewire::test(UnmatchedDepositsPage::class)->call('setTab', 'house_funded');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'finance/deposits') && ($request['kind'] ?? null) === 'house_funding');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'deposits/unmatched') && ($request['status'] ?? null) === 'house_funded');
});

it('narrows the income statement and the ledger to one product', function (): void {
    signInWithRole('finance');
    Http::fake([
        'https://game-api.test/products*' => Http::response(['data' => [['code' => 'slots_fruit', 'game' => 'slots', 'name' => 'Fruit Party']]]),
        'https://game-api.test/finance/*' => Http::response(['success' => true, 'data' => ['meta' => ['warnings' => []], 'summary' => [], 'items' => [], 'pagination' => []]]),
    ]);

    Livewire::test(IncomeStatementReport::class)
        ->callAction('filterProduct', data: ['product' => 'slots_fruit'])
        ->assertSee('Fruit Party (slots)');
    Livewire::test(LedgerReport::class)->filterTable('product', 'slots_fruit');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'income-statement') && ($request['product'] ?? null) === 'slots_fruit');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'finance/ledger') && ($request['product'] ?? null) === 'slots_fruit');
});
