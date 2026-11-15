<?php

use App\Exceptions\SiteBackofficeException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SiteBackofficeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

use function Pest\Laravel\travelTo;

const SITE = 'https://kadi.test';
const SUSPEND_URL = SITE.'/api/v1/backoffice/sports/markets/42/suspend';

beforeEach(function (): void {
    config([
        'services.kadi_site.url' => SITE,
        'services.kadi_site.secret' => 'test-secret',
    ]);
    Http::preventStrayRequests();
    Sleep::fake();
});

function suspendMarket(User $staff, ?string $requestId = null)
{
    return app(SiteBackofficeClient::class)->write('POST', 'markets/42/suspend', ['reason' => 'Injury news'], $staff, 'SbMarket', 42, 'suspend', $requestId);
}

it('signs a write exactly as the site documents it and names the staff member', function (): void {
    travelTo(Carbon::createFromTimestamp(1763200000));
    $staff = User::factory()->create(['id' => 7, 'name' => 'Jane Trader', 'email' => 'jane@example.com']);
    Http::fake([SUSPEND_URL => Http::response(['status' => 'ok', 'data' => ['market' => 42, 'status' => 'suspended']])]);

    $result = suspendMarket($staff, 'req-0001-abcd');

    expect($result->data)->toBe(['market' => 42, 'status' => 'suspended'])
        ->and($result->replayed)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->body() === '{"reason":"Injury news","staff":{"id":"7","name":"Jane Trader","email":"jane@example.com"}}'
        && $request->hasHeader('X-Backoffice-Timestamp', '1763200000')
        && $request->hasHeader('X-Backoffice-Request-Id', 'req-0001-abcd')
        && $request->hasHeader('Content-Type', 'application/json')
        // HMAC-SHA256("test-secret", "1763200000.req-0001-abcd.POST./api/v1/backoffice/sports/markets/42/suspend.{sha256(body)}"), computed with openssl.
        && $request->hasHeader('X-Backoffice-Signature', '2692bab17af3aa44175c1377ef57eff677262308078d70af87629f946b18c161'));
});

it('signs a read over its path and query string and an empty body', function (): void {
    Http::fake([SITE.'/api/v1/backoffice/sports/reconciliation?date=2026-11-15' => Http::response(['status' => 'ok', 'data' => ['date' => '2026-11-15']])]);

    $result = app(SiteBackofficeClient::class)->get('reconciliation', ['date' => '2026-11-15']);

    expect($result->data)->toBe(['date' => '2026-11-15']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->body() === ''
        && siteAcceptsSignature($request));
});

it('signs the full path when the site is served below a base path', function (): void {
    config(['services.kadi_site.url' => SITE.'/kadi/']);
    Http::fake([SITE.'/kadi/api/v1/backoffice/sports/odds-api/credits' => Http::response(['status' => 'ok', 'data' => []])]);

    app(SiteBackofficeClient::class)->get('odds-api/credits');

    Http::assertSent(fn (Request $request): bool => siteAcceptsSignature($request));
});

it('refuses to call the site when GMS has no signing secret', function (): void {
    config(['services.kadi_site.secret' => '']);
    Http::fake();

    expect(fn () => suspendMarket(User::factory()->create()))
        ->toThrow(SiteBackofficeException::class, 'not set up in GMS');

    Http::assertNothingSent();
    expect(AuditLog::query()->where('auditable_type', 'like', 'KadiSite%')->exists())->toBeFalse();
});

it('retries a write that timed out with the same request id and a fresh signature', function (): void {
    $staff = User::factory()->create();
    Http::fakeSequence(SUSPEND_URL)
        ->pushFailedConnection()
        ->push(['status' => 'ok', 'data' => ['status' => 'suspended']]);

    $result = suspendMarket($staff, 'req-retry-0001');

    $requestIds = [];
    Http::assertSent(function (Request $request) use (&$requestIds): bool {
        $requestIds[] = $request->header('X-Backoffice-Request-Id')[0];

        return siteAcceptsSignature($request);
    });
    expect($requestIds)->toBe(['req-retry-0001', 'req-retry-0001'])
        ->and($result->requestId)->toBe('req-retry-0001');
    Sleep::assertSleptTimes(1);
});

it('reports an unknown outcome with the request id when the site never answers a write', function (): void {
    Http::fake([SUSPEND_URL => Http::failedConnection()]);

    try {
        suspendMarket(User::factory()->create(), 'req-unknown-01');
        $this->fail('Expected the write to fail.');
    } catch (SiteBackofficeException $e) {
        expect($e->outcomeUnknown())->toBeTrue()
            ->and($e->requestId)->toBe('req-unknown-01')
            ->and($e->statusCode)->toBe(0)
            ->and($e->getMessage())->toContain('not known whether the action ran');
    }

    Http::assertSentCount(3);
});

it('waits and retries while the site is still handling the same request id', function (): void {
    Http::fakeSequence(SUSPEND_URL)
        ->push(['message' => 'This request is still being handled.'], 409)
        ->push(['status' => 'ok', 'data' => ['status' => 'suspended']], 200, ['Backoffice-Replayed' => 'true']);

    $result = suspendMarket(User::factory()->create());

    expect($result->replayed)->toBeTrue();
    Http::assertSentCount(2);
});

it('shows a refusal with its code and never retries it', function (): void {
    $staff = User::factory()->create();
    Http::fake([SITE.'/api/v1/backoffice/sports/markets/42/reopen' => Http::response([
        'message' => 'The match has kicked off.',
        'code' => 'market_cannot_be_reopened',
    ], 409)]);

    try {
        app(SiteBackofficeClient::class)->write('POST', 'markets/42/reopen', [], $staff, 'SbMarket', 42, 'reopen');
        $this->fail('Expected the reopen to be refused.');
    } catch (SiteBackofficeException $e) {
        expect($e->getMessage())->toBe('The match has kicked off.')
            ->and($e->errorCode)->toBe('market_cannot_be_reopened')
            ->and($e->outcomeUnknown())->toBeFalse();
    }

    Http::assertSentCount(1);
    $log = AuditLog::query()->where('auditable_type', 'like', 'KadiSite%')->sole();
    expect($log->user_id)->toBe($staff->id)
        ->and($log->auditable_type)->toBe('KadiSite\\SbMarket')
        ->and($log->event)->toBe('reopen')
        ->and($log->new_values['status'])->toBe(409)
        ->and($log->new_values['response'])->toBe(['message' => 'The match has kicked off.', 'code' => 'market_cannot_be_reopened'])
        ->and($log->new_values['payload'])->not->toHaveKey('staff');
});

it('returns validation errors from the site', function (): void {
    Http::fake([SUSPEND_URL => Http::response([
        'message' => 'The reason field is required.',
        'errors' => ['reason' => ['The reason field is required.']],
    ], 422)]);

    expect(fn () => suspendMarket(User::factory()->create()))
        ->toThrow(fn (SiteBackofficeException $e) => expect($e->errors)->toBe(['reason' => ['The reason field is required.']]));

    Http::assertSentCount(1);
});

it('waits out a short rate limit once and retries', function (): void {
    Http::fakeSequence(SUSPEND_URL)
        ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '2'])
        ->push(['status' => 'ok', 'data' => []]);

    suspendMarket(User::factory()->create());

    Sleep::assertSequence([Sleep::for(2)->seconds()]);
    Http::assertSentCount(2);
});

it('gives up on a long rate limit and says how long to wait', function (): void {
    Http::fake([SUSPEND_URL => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '45'])]);

    expect(fn () => suspendMarket(User::factory()->create()))
        ->toThrow(SiteBackofficeException::class, 'Try again in 45 seconds.');

    Sleep::assertNeverSlept();
    Http::assertSentCount(1);
});

it('explains signature and switched-off refusals', function (int $status, string $message): void {
    Http::fake([SUSPEND_URL => Http::response(['message' => 'Unauthenticated.'], $status)]);

    expect(fn () => suspendMarket(User::factory()->create()))
        ->toThrow(SiteBackofficeException::class, $message);

    Http::assertSentCount(1);
})->with([
    'bad signature or stale timestamp' => [401, 'refused the signature'],
    'no secret on the site' => [503, 'BACKOFFICE_GMS_SECRETS is empty on the site'],
]);

it('retries a read on a server error and then fails without an unknown outcome', function (): void {
    Http::fake([SITE.'/api/v1/backoffice/sports/exposure?limit=100' => Http::response(['message' => 'Server Error'], 500)]);

    expect(fn () => app(SiteBackofficeClient::class)->get('exposure', ['limit' => 100]))
        ->toThrow(fn (SiteBackofficeException $e) => expect($e->outcomeUnknown())->toBeFalse());

    Http::assertSentCount(3);
});

it('logs failures without the secret, signature or body', function (): void {
    Log::spy();
    Http::fake([SUSPEND_URL => Http::response(['message' => 'Unauthenticated.'], 401)]);

    rescue(fn () => suspendMarket(User::factory()->create(), 'req-logged-01'), report: false);

    Log::shouldHaveReceived('warning')->once()->with('Kadi site back-office call failed', [
        'method' => 'POST',
        'uri' => '/api/v1/backoffice/sports/markets/42/suspend',
        'status' => 401,
        'request_id' => 'req-logged-01',
    ]);
});
