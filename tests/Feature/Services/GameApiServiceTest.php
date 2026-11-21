<?php

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
    ]);
});

it('sends a unique random idempotency key by default on mutating requests', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $service = app(GameApiService::class);
    $service->placeBet(1, 2, 50.0);
    $service->placeBet(1, 2, 50.0);

    $keys = [];
    Http::assertSentCount(2);
    Http::assertSent(function ($request) use (&$keys) {
        $keys[] = $request->header('Idempotency-Key')[0] ?? null;

        return true;
    });

    expect($keys)->each->not->toBeNull();
    expect($keys[0])->not->toBe($keys[1]);
});

it('does not send an idempotency key on read-only POST lookups', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $service = app(GameApiService::class);
    $service->getLeaderboard('2026-01-01', '2026-01-31');
    $service->getCombinedLeaderboard();
    $service->getPlayerGameStats(5, '2026-01-01', '2026-01-31');
    $service->getGameIncomeBreakdown('2026-01-01', '2026-01-31');

    Http::assertSentCount(4);
    Http::assertNotSent(fn ($request) => $request->hasHeader('Idempotency-Key'));
});

it('honors an explicitly passed idempotency key', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $service = app(GameApiService::class);
    $service->getLeaderboard('2026-01-01', '2026-01-31', 'my-explicit-key');

    Http::assertSent(fn ($request) => $request->header('Idempotency-Key')[0] === 'my-explicit-key');
});

it('sends the same deterministic idempotency key for createCustomer with the same account_no', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $service = app(GameApiService::class);
    $data = ['account_no' => 'ACC-123', 'name' => 'A', 'email' => 'a@example.com'];

    $service->createCustomer($data);
    $service->createCustomer($data);

    $keys = [];
    Http::assertSent(function ($request) use (&$keys) {
        $keys[] = $request->header('Idempotency-Key')[0] ?? null;

        return true;
    });

    expect($keys)->toHaveCount(2);
    expect($keys[0])->toBe($keys[1])
        ->and($keys[0])->toBe('customer-create-ACC-123');
});

it('does not send an idempotency key header on GET requests', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $service = app(GameApiService::class);
    $service->listCustomers();

    Http::assertSent(fn ($request) => $request->method() === 'GET' && ! $request->hasHeader('Idempotency-Key'));
});

it('requests the combined leaderboard with POST', function () {
    Http::fake(['*' => Http::response(['leaderboard' => [['id' => 1, 'name' => 'A', 'total_wins' => 10]]], 200)]);

    $result = app(GameApiService::class)->getCombinedLeaderboard();

    expect($result['leaderboard'])->toHaveCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/customers/combined-leaderboard'));
});

it('lists withdrawals from the /withdraws route', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    app(GameApiService::class)->listWithdrawals();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/withdraws'));
});

it('turns a 429 into a friendly rate limit exception', function () {
    Http::fake(['*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30'])]);

    try {
        app(GameApiService::class)->listWithdrawals();
        $this->fail('Expected a GameApiException');
    } catch (GameApiException $e) {
        expect($e->statusCode)->toBe(429)
            ->and($e->apiMessage)->toContain('Rate limit')
            ->and($e->apiMessage)->toContain('30');
    }
});

it('retries a GET once after a server error', function () {
    Http::fakeSequence()
        ->push(['message' => 'Internal server error'], 500)
        ->push(['data' => ['today' => 3]], 200);

    expect(app(GameApiService::class)->listWithdrawals())->toBe(['data' => ['today' => 3]]);
    Http::assertSentCount(2);
});

it('never retries a mutation after a server error', function () {
    Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

    expect(fn () => app(GameApiService::class)->placeBet(1, 2, 50.0))->toThrow(GameApiException::class);
    Http::assertSentCount(1);
});

it('fetches a finance report with cleaned query filters and caches it', function () {
    Cache::flush();
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['items' => [1]]], 200)]);

    $service = app(GameApiService::class);
    $filters = ['to' => '2026-09-21', 'from' => '2026-09-01', 'status' => null, 'kind' => '', 'exclude_test' => false, 'page' => 2];

    $first = $service->financeReport('deposits', $filters);
    $second = $service->financeReport('deposits', $filters);

    expect($first)->toBe(['items' => [1]])->and($second)->toBe($first);
    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/finance/deposits?')
            && $query === ['exclude_test' => '0', 'from' => '2026-09-01', 'page' => '2', 'to' => '2026-09-21'];
    });
});

it('downloads a finance csv server-side using the content disposition filename', function () {
    Http::fake(['*' => Http::response("\xEF\xBB\xBFid,amount\n1,10\n", 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="finance-deposits-2026-09-01-2026-09-21.csv"',
    ])]);

    $csv = app(GameApiService::class)->downloadFinanceCsv('deposits', ['from' => '2026-09-01', 'to' => '2026-09-21']);

    expect($csv['filename'])->toBe('finance-deposits-2026-09-01-2026-09-21.csv')
        ->and($csv['body'])->toContain('id,amount');
    Http::assertSent(fn ($request) => $request->hasHeader('X-API-KEY', 'test-api-key')
        && str_contains($request->url(), '/finance/export/deposits'));
});

it('refuses to export a report the API does not offer', function () {
    Http::fake();

    expect(fn () => app(GameApiService::class)->downloadFinanceCsv('secrets'))->toThrow(GameApiException::class);
    Http::assertNothingSent();
});

it('falls back to the balance sheet b2c accounts when /b2c/balance is empty', function () {
    Cache::flush();
    Http::fake([
        '*/b2c/balance' => Http::response(['data' => null], 200),
        '*/finance/balance-sheet*' => Http::response(['data' => ['assets' => ['cash' => ['accounts' => [
            ['type' => 'b2c', 'account' => 'Utility Account', 'amount' => 28772],
            ['type' => 'b2c', 'account' => 'Working Account', 'amount' => 100],
            ['type' => 'c2b', 'account' => 'Utility Account', 'amount' => 5000],
        ]]]]], 200),
    ]);

    expect(app(GameApiService::class)->getB2CBalanceAmount())->toBe(28872.0);
});
