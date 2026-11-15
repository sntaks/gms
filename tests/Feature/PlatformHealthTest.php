<?php

use App\Enums\PayoutStatus;
use App\Filament\Pages\PlatformHealthPage;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\Site\SbFixture;
use App\Models\Site\SbWagerOp;
use App\Services\PlatformHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SiteSchema;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
    ]);
    useFakeSiteBackoffice();
    SiteSchema::create();
    Cache::flush();
    Sleep::fake();
    Http::preventStrayRequests();
    travelTo('2026-11-16 12:00:00');
});

/**
 * @param  array<string, mixed>  $overrides  URL pattern => response
 */
function fakeHealthySystems(array $overrides = []): void
{
    // Overrides first, so their pattern wins; `+` also keeps an override over a default with the same key.
    Http::fake($overrides + [
        'https://game-api.test/games*' => Http::response(['data' => [['code' => 'slots', 'name' => 'Slots']]]),
        'https://game-api.test/withdrawals/pending*' => Http::response(['success' => true, 'data' => ['items' => [], 'pagination' => ['total' => 0]]]),
        'https://game-api.test/deposits/unmatched*' => Http::response(['success' => true, 'data' => ['summary' => ['unmatched_count' => 0, 'unmatched_amount' => 0], 'items' => []]]),
        'https://kadi.test/api/v1/backoffice/sports/odds-api/credits' => Http::response(['status' => 'ok', 'data' => ['remaining' => 4000, 'reserve' => 500, 'monthly_cap' => 10000]]),
    ]);
}

/**
 * @return array<string, array{key: string, group: string, label: string, status: string, value: string, detail: string|null}>
 */
function healthByKey(): array
{
    return collect(app(PlatformHealth::class)->checks())->keyBy('key')->all();
}

it('reports every connection and backlog as OK when all is well', function (): void {
    fakeHealthySystems();
    SbFixture::factory()->create();

    $checks = healthByKey();

    expect(collect($checks)->except(['site_health', 'wallet_webhook'])->pluck('status')->unique()->all())->toBe([PlatformHealth::OK])
        ->and($checks['site_backoffice']['detail'])->toBe('4,000 Odds API credits left this month (reserve 500)')
        ->and($checks['site_health']['status'])->toBe(PlatformHealth::UNKNOWN)
        ->and($checks['site_health']['detail'])->toContain('G3')
        ->and($checks['wallet_webhook']['detail'])->toContain('G9');
});

it('keeps checking everything else when the Kadi API is down', function (): void {
    fakeHealthySystems([
        'https://game-api.test/*' => Http::response(['message' => 'Server Error'], 500),
    ]);

    $checks = healthByKey();

    expect($checks['kadi_api']['status'])->toBe(PlatformHealth::FAILING)
        ->and($checks['held_withdrawals']['status'])->toBe(PlatformHealth::FAILING)
        ->and($checks['site_backoffice']['status'])->toBe(PlatformHealth::OK)
        ->and($checks['site_database']['status'])->toBe(PlatformHealth::OK);
});

it('fails the back office check when GMS has no signing secret', function (): void {
    fakeHealthySystems();
    config(['services.kadi_site.secret' => '']);

    expect(healthByKey()['site_backoffice'])->toMatchArray(['status' => PlatformHealth::FAILING, 'value' => 'Not set up']);
});

it('flags failed wager ops, overdue held withdrawals and payouts the API books refused', function (): void {
    fakeHealthySystems([
        'https://game-api.test/withdrawals/pending*' => Http::response(['success' => true, 'data' => ['items' => [
            ['withdrawal_id' => 1, 'status' => 'awaiting', 'requested_at' => '2026-11-14T08:00:00+03:00'],
        ], 'pagination' => ['total' => 1]]]),
    ]);
    SbWagerOp::factory()->create(['status' => 'failed']);
    signInWithRole('super-admin');
    $payee = Payee::create(['name' => 'Achieng Otieno', 'phone' => '254712345678', 'designation' => 'Accountant', 'team' => 'Finance']);
    Payout::create(['payee_id' => $payee->id, 'amount' => 1500, 'reason' => 'Fee'])
        ->forceFill(['status' => PayoutStatus::Completed, 'api_expense_error' => 'Kadi API down'])->saveQuietly();

    $checks = healthByKey();

    expect($checks['wager_ops'])->toMatchArray(['status' => PlatformHealth::FAILING, 'value' => '1 (1 failed)'])
        ->and($checks['held_withdrawals'])->toMatchArray(['status' => PlatformHealth::WARNING, 'detail' => '1 held over 24 hours.'])
        ->and($checks['payout_expenses'])->toMatchArray(['status' => PlatformHealth::WARNING, 'value' => '1 failed']);
});

it('shows the health page to managers and keeps agents out', function (): void {
    fakeHealthySystems();

    signInWithRole('manager');
    get(PlatformHealthPage::getUrl())->assertOk()->assertSee('Kadi site back office (signed)')->assertSee('Not readable from GMS');

    signInWithRole('agent');
    get(PlatformHealthPage::getUrl())->assertForbidden();
});
