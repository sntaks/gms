<?php

use App\Filament\Pages\SportsbookBetDetailPage;
use App\Filament\Pages\SportsbookBetsPage;
use App\Filament\Pages\SportsbookInPlayPage;
use App\Filament\Pages\Widgets\InPlaySummaryWidget;
use App\Models\Site\SbBet;
use App\Models\Site\SbFixture;
use App\Models\Site\SbMarket;
use App\Support\SportsbookFormat;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\SiteSchema;

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    SiteSchema::create();
    Http::preventStrayRequests();
    Sleep::fake();
    config(['services.game_api.url' => 'https://game-api.test', 'services.game_api.key' => 'test-api-key']);
});

/**
 * A match kicked off 30 minutes ago whose newest feed price is `$priceAge` seconds old (null: none).
 */
function liveMatch(?int $priceAge, array $attributes = []): SbFixture
{
    $fixture = SbFixture::factory()->create(['kicks_off_at' => now()->subMinutes(30), ...$attributes]);
    SbMarket::factory()->create([
        'fixture_id' => $fixture->id,
        'source' => $priceAge === null ? 'derived' : 'feed',
        'priced_at' => now()->subSeconds($priceAge ?? 5),
    ]);

    return $fixture;
}

it('judges live prices by the site\'s suspension and alert ages', function (?int $age, string $health): void {
    expect(SportsbookFormat::feedHealth($age))->toBe($health);
})->with([
    'just priced' => [30, 'fresh'],
    'at the suspension age' => [90, 'ageing'],
    'just under the alert' => [119, 'ageing'],
    'at the alert' => [120, 'stale'],
    'no live price' => [null, 'none'],
]);

it('lists only live matches with the health of their live prices', function (): void {
    freezeTime();
    signInWithRole('trader');
    $fresh = liveMatch(30, ['home_team' => 'Arsenal', 'away_team' => 'Chelsea', 'home_score' => 1, 'away_score' => 0]);
    $stale = liveMatch(150);
    $unpriced = liveMatch(null);
    $upcoming = SbFixture::factory()->create(['kicks_off_at' => now()->addHour()]);
    $finished = SbFixture::factory()->create(['kicks_off_at' => now()->subHours(2), 'feed_completed_at' => now()->subMinutes(5)]);

    Livewire::test(SportsbookInPlayPage::class)
        ->assertCanSeeTableRecords([$fresh, $stale, $unpriced])
        ->assertCanNotSeeTableRecords([$upcoming, $finished])
        ->assertTableColumnStateSet('feed', 'fresh', $fresh)
        ->assertTableColumnStateSet('feed', 'stale', $stale)
        ->assertTableColumnStateSet('feed', 'none', $unpriced)
        ->assertTableColumnStateSet('score', '1 – 0', $fresh);
});

it('says why live markets are suspended', function (): void {
    $fixture = liveMatch(10);
    SbMarket::factory()->count(2)->create(['fixture_id' => $fixture->id, 'status' => 'suspended', 'suspension_reason' => 'score-changed']);
    SbMarket::factory()->create(['fixture_id' => $fixture->id, 'status' => 'suspended', 'suspension_reason' => 'line-decided']);

    expect(SportsbookInPlayPage::marketsLine($fixture->markets()->get()))
        ->toBe('1 open · 3 suspended (Score just changed ×2, Line decided by the score)');
});

it('sums up live matches, stale prices, held bets and recent rejections', function (): void {
    freezeTime();
    liveMatch(20);
    liveMatch(200);
    SbBet::factory()->create(['status' => 'pending', 'product' => 'sportsbook_live', 'accept_after' => now()->addSeconds(5)]);
    SbBet::factory()->create(['status' => 'rejected', 'product' => 'sportsbook_live', 'reject_reason' => 'live_score_changed', 'placed_at' => now()->subMinutes(20)]);
    SbBet::factory()->create(['status' => 'rejected', 'product' => 'sportsbook_live', 'placed_at' => now()->subHours(2)]);

    $stats = collect((fn () => $this->getStats())->call(new InPlaySummaryWidget))
        ->mapWithKeys(fn (Stat $stat): array => [$stat->getLabel() => $stat->getValue()]);

    expect($stats->all())->toBe([
        'Live matches' => '2',
        'Stale live prices' => '1',
        'Held for the live delay' => '1',
        'Rejected in play, last hour' => '1',
    ]);
});

it('explains why the re-check rejected an in-play bet', function (): void {
    signInWithRole('manager');
    $bet = SbBet::factory()->create(['status' => 'rejected', 'product' => 'sportsbook_live', 'reject_reason' => 'live_score_changed']);

    Livewire::test(SportsbookBetsPage::class)
        ->call('setTab', 'rejected')
        ->assertCanSeeTableRecords([$bet])
        ->assertSee('Score changed during the delay');
});

it('compares a bet with its Kadi API wager', function (array $wager, string $expected): void {
    signInWithRole('manager');
    $bet = SbBet::factory()->create(['status' => 'won', 'payout' => 21000, 'settlement_version' => 1, 'wager_reference' => '0b9a6f6e-3c2d-4c1e-9d4a-6a1e2f3b4c5d']);
    Http::fake(['https://game-api.test/wagers*' => Http::response(['success' => true, 'data' => ['items' => [$wager], 'pagination' => []]])]);

    get(SportsbookBetDetailPage::getUrl(['bet' => $bet->id]))->assertSee($expected);

    Http::assertSent(fn ($request): bool => $request['reference'] === '0b9a6f6e-3c2d-4c1e-9d4a-6a1e2f3b4c5d');
})->with([
    'in agreement' => [
        ['reference' => '0b9a6f6e-3c2d-4c1e-9d4a-6a1e2f3b4c5d', 'product' => 'sportsbook', 'status' => 'won', 'payout' => 210.0, 'settlement_version' => 1],
        'The site and the Kadi API agree.',
    ],
    'not settled on the API yet' => [
        ['reference' => '0b9a6f6e-3c2d-4c1e-9d4a-6a1e2f3b4c5d', 'product' => 'sportsbook', 'status' => 'open', 'payout' => 0.0, 'settlement_version' => 0],
        'They differ on status: site won, Kadi API open; payout: site KES 210.00, Kadi API KES 0.00; settlement version: site 1, Kadi API 0.',
    ],
]);

it('flags a settled bet the Kadi API has no wager for', function (): void {
    signInWithRole('manager');
    $bet = SbBet::factory()->create(['status' => 'lost']);
    Http::fake(['https://game-api.test/wagers*' => Http::response(['success' => true, 'data' => ['items' => [], 'pagination' => []]])]);

    get(SportsbookBetDetailPage::getUrl(['bet' => $bet->id]))
        ->assertSee('The Kadi API has no wager with this reference.');
});

it('still opens the bet when the Kadi API is down', function (): void {
    signInWithRole('manager');
    $bet = SbBet::factory()->create(['status' => 'lost', 'reference' => 'KB7Q2X9A']);
    Http::fake(['https://game-api.test/wagers*' => Http::failedConnection()]);

    get(SportsbookBetDetailPage::getUrl(['bet' => $bet->id]))
        ->assertSee('Bet KB7Q2X9A')
        ->assertSee('KadiApi could not be');
});
