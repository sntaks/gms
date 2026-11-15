<?php

use App\Filament\Pages\SportsbookReconciliationReport;
use App\Services\SportsbookReconciliation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SiteSchema;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

const RECONCILIATION_URL = 'https://kadi.test/api/v1/backoffice/sports/reconciliation?date=2026-11-14';
const HOUSE_BANKED_URL = 'https://game-api.test/finance/house-banked*';

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    SiteSchema::create();
    useFakeSiteBackoffice();
    config(['services.game_api.url' => 'https://game-api.test', 'services.game_api.key' => 'test-api-key']);
    Cache::flush();
    Http::preventStrayRequests();
    Sleep::fake();
    travelTo('2026-11-15 10:00:00');
});

/**
 * The site's answer for 14 Nov: 12 sports bets settled (7 won, 4 lost, 1 void) paying KES 3,150.00
 * with KES 75.00 tax, and 4 jackpot entries settled (1 won, 3 lost) with a KES 10,000.00 prize.
 *
 * @param  array<string, mixed>  $sportsbook
 * @return array<string, mixed>
 */
function siteDay(array $sportsbook = [], array $needsOperator = []): array
{
    return ['status' => 'ok', 'data' => [
        'date' => '2026-11-14',
        'timezone' => 'Africa/Nairobi',
        'products' => [
            'sportsbook' => [
                'placed' => ['won' => 7, 'lost' => 4, 'void' => 1],
                'cash_stakes' => 120000, 'free_bet_stakes' => 0,
                'settled' => ['won' => 7, 'lost' => 4, 'void' => 1],
                'payouts' => 315000, 'refunds' => 5000, 'tax' => 7500,
                ...$sportsbook,
            ],
            'sportsbook_jackpot' => [
                'placed' => ['lost' => 3, 'won' => 1], 'stakes' => 39600,
                'settled' => ['won' => 1, 'lost' => 3],
                'prizes' => 1000000, 'refunds' => 0, 'tax' => 0,
            ],
        ],
        'needs_operator' => ['ops' => [], 'pending_bets' => [], 'pending_jackpot_entries' => [], ...$needsOperator],
    ]];
}

/**
 * The Kadi API's house-banked report for the same day, per product (amounts in KES).
 *
 * @param  list<array<string, mixed>>  $extraProducts
 */
function apiDay(array $sportsbook = [], array $extraProducts = []): array
{
    return ['success' => true, 'data' => ['by_product' => [
        ['product' => 'sportsbook', 'game' => 'sportsbook', 'bets' => 11, 'voids' => 1, 'turnover' => 1150.0, 'payouts' => 3000.0, 'bonuses' => 150.0, 'free_bet_winnings' => 0.0, 'winnings_tax_withheld' => 75.0, ...$sportsbook],
        ['product' => 'sportsbook_jackpot', 'game' => 'sportsbook', 'bets' => 4, 'voids' => 0, 'turnover' => 396.0, 'payouts' => 10000.0, 'bonuses' => 0.0, 'free_bet_winnings' => 0.0, 'winnings_tax_withheld' => 0.0],
        ...$extraProducts,
    ]]];
}

function statusesByKey(array $rows): array
{
    return collect($rows)->pluck('status', 'key')->all();
}

it('matches the site and the Kadi API settlement by settlement', function (): void {
    Http::fake([RECONCILIATION_URL => Http::response(siteDay()), HOUSE_BANKED_URL => Http::response(apiDay())]);

    $result = app(SportsbookReconciliation::class)->forDay('2026-11-14');

    expect($result['errors'])->toBe([])
        ->and($result['warnings'])->toBe([])
        ->and(collect($result['rows'])->where('status', 'differs'))->toBeEmpty()
        ->and(collect($result['rows'])->where('status', 'info')->pluck('key')->all())->toBe(['sportsbook.stakes', 'sportsbook_jackpot.stakes']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://game-api.test/finance/house-banked?exclude_test=0&from=2026-11-14&to=2026-11-14');
});

it('shows where the site and the Kadi API differ', function (): void {
    Http::fake([
        RECONCILIATION_URL => Http::response(siteDay(['settled' => ['won' => 8, 'lost' => 4, 'void' => 1], 'payouts' => 355000])),
        HOUSE_BANKED_URL => Http::response(apiDay()),
    ]);

    $rows = collect(app(SportsbookReconciliation::class)->forDay('2026-11-14')['rows'])->keyBy('key');

    expect($rows['sportsbook.settled-won-or-lost'])->toMatchArray(['site' => '12', 'api' => '11', 'difference' => '+1', 'status' => 'differs'])
        ->and($rows['sportsbook.payouts-incl-bonus-before-tax'])->toMatchArray(['site' => 'KES 3,550.00', 'api' => 'KES 3,150.00', 'difference' => '+KES 400.00', 'status' => 'differs'])
        ->and($rows['sportsbook.winnings-tax']['status'])->toBe('match');
});

it('counts the API\'s in-play product with the site\'s sports bets until the site splits them', function (): void {
    Http::fake([
        RECONCILIATION_URL => Http::response(siteDay(['settled' => ['won' => 9, 'lost' => 4, 'void' => 1], 'payouts' => 365000])),
        HOUSE_BANKED_URL => Http::response(apiDay(extraProducts: [
            ['product' => 'sportsbook_live', 'game' => 'sportsbook', 'bets' => 2, 'voids' => 0, 'turnover' => 200.0, 'payouts' => 500.0, 'bonuses' => 0.0, 'free_bet_winnings' => 0.0, 'winnings_tax_withheld' => 0.0],
        ])),
    ]);

    $result = app(SportsbookReconciliation::class)->forDay('2026-11-14');

    expect($result['warnings'])->toBe([SportsbookReconciliation::LIVE_SPLIT_WARNING])
        ->and(collect($result['rows'])->where('status', 'differs'))->toBeEmpty();
});

it('says which side could not be read', function (): void {
    Http::fake([
        RECONCILIATION_URL => Http::response(['message' => 'The back-office API is not enabled.'], 503),
        HOUSE_BANKED_URL => Http::response(apiDay()),
    ]);

    $result = app(SportsbookReconciliation::class)->forDay('2026-11-14');

    expect($result['rows'])->toBe([])
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('Kadi site:');
});

it('shows the day and what needs an operator to finance', function (): void {
    signInWithRole('finance');
    Http::fake([
        RECONCILIATION_URL => Http::response(siteDay(needsOperator: [
            'ops' => [['id' => 9, 'product' => 'sportsbook', 'wager_reference' => 'ref', 'operation' => 'settle', 'version' => 2, 'status' => 'failed', 'attempts' => 5, 'last_status' => 422, 'last_error' => 'payout_above_limit']],
            'pending_bets' => ['KB7Q2X9A'],
        ])),
        HOUSE_BANKED_URL => Http::response(apiDay()),
    ]);

    get(SportsbookReconciliationReport::getUrl(['date' => '2026-11-14']))
        ->assertSee('Saturday 14 Nov 2026')
        ->assertSee('Sports bets (pre-match and in play)')
        ->assertSee('Settle v2')
        ->assertSee('HTTP 422 payout_above_limit')
        ->assertSee('KB7Q2X9A');
});

it('never reconciles a day that has not happened', function (): void {
    signInWithRole('finance');
    Http::fake([
        'https://kadi.test/api/v1/backoffice/sports/reconciliation?date=2026-11-15' => Http::response(siteDay()),
        HOUSE_BANKED_URL => Http::response(apiDay()),
    ]);

    get(SportsbookReconciliationReport::getUrl(['date' => '2027-01-01']))->assertSee('Sunday 15 Nov 2026');
});

it('is for staff who see both finance reports and the sportsbook', function (string $role, bool $allowed): void {
    signInWithRole($role);
    Http::fake([
        'https://kadi.test/api/v1/backoffice/sports/reconciliation*' => Http::response(siteDay()),
        HOUSE_BANKED_URL => Http::response(apiDay()),
    ]);

    $response = get(SportsbookReconciliationReport::getUrl());

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'finance' => ['finance', true],
    'admin' => ['admin', true],
    'manager (no finance reports)' => ['manager', false],
    'trader (no finance reports)' => ['trader', false],
    'agent (no finance reports, no sportsbook)' => ['agent', false],
]);
