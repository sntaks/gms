<?php

use App\Exceptions\ReadOnlySiteModelException;
use App\Models\Site\SbBet;
use App\Models\Site\SbFixture;
use App\Models\Site\SbMarket;
use App\Models\Site\SbSelection;
use App\Models\Site\SiteActivity;
use App\Models\Site\SiteUser;
use Illuminate\Support\Facades\DB;
use Tests\Support\SiteSchema;

beforeEach(function (): void {
    SiteSchema::create();
});

it('refuses to save, update or delete site rows', function (Closure $write): void {
    $market = SbMarket::factory()->create(['status' => 'open']);

    expect(fn () => $write($market))->toThrow(ReadOnlySiteModelException::class);

    expect($market->fresh()->status)->toBe('open');
})->with([
    'save' => [fn (SbMarket $market) => $market->forceFill(['status' => 'suspended'])->save()],
    'model update' => [fn (SbMarket $market) => $market->update(['status' => 'suspended'])],
    'query update' => [fn (SbMarket $market) => SbMarket::query()->whereKey($market->id)->update(['status' => 'suspended'])],
    'delete' => [fn (SbMarket $market) => $market->delete()],
    'query delete' => [fn (SbMarket $market) => SbMarket::query()->whereKey($market->id)->delete()],
]);

it('refuses to insert site rows', function (): void {
    expect(fn () => SbMarket::query()->insert(['fixture_id' => 1, 'type' => 'h2h', 'key' => 'h2h']))
        ->toThrow(ReadOnlySiteModelException::class)
        ->and(fn () => SbMarket::create(['fixture_id' => 1, 'type' => 'h2h', 'key' => 'h2h']))
        ->toThrow(ReadOnlySiteModelException::class);

    expect(DB::connection('kadi_site')->table('sb_markets')->count())->toBe(0);
});

it('reads the site timestamps as UTC', function (): void {
    $fixtureId = SbFixture::factory()->create()->id;
    DB::connection('kadi_site')->table('sb_fixtures')->where('id', $fixtureId)->update(['kicks_off_at' => '2026-11-21 12:30:00']);

    $kickOff = SbFixture::findOrFail($fixtureId)->kicks_off_at;

    expect($kickOff->toIso8601String())->toBe('2026-11-21T12:30:00+00:00')
        ->and($kickOff->timezone('Africa/Nairobi')->format('H:i'))->toBe('15:30');
});

it('selects only the user columns the read-only grant allows', function (): void {
    $user = SiteUser::factory()->create(['name' => 'Wanjiku', 'email' => 'wanjiku@example.com', 'linked_id' => 5501]);

    expect(SiteUser::findOrFail($user->id)->toArray())->toBe([
        'id' => $user->id,
        'name' => 'Wanjiku',
        'email' => 'wanjiku@example.com',
        'linked_id' => 5501,
    ]);
});

it('prices a selection with the staff override before the feed price', function (?string $override, string $expected): void {
    $selection = SbSelection::factory()->create(['feed_price' => '2.10', 'override_price' => $override]);

    expect($selection->fresh()->effectivePrice())->toBe($expected);
})->with([
    'no override' => [null, '2.10'],
    'override' => ['1.95', '1.95'],
]);

it('treats a bet without a product as a pre-match sportsbook wager', function (?string $product, string $expected): void {
    $bet = SbBet::factory()->create(['product' => $product]);

    expect($bet->fresh()->kadiProduct())->toBe($expected);
})->with([
    'pre-match' => [null, 'sportsbook'],
    'in play' => ['sportsbook_live', 'sportsbook_live'],
]);

it('takes the last recorded result of a fixture as its result', function (): void {
    $fixture = SbFixture::factory()->create();
    DB::connection('kadi_site')->table('sb_fixture_results')->insert([
        ['fixture_id' => $fixture->id, 'home_score' => 1, 'away_score' => 0, 'source' => 'feed'],
        ['fixture_id' => $fixture->id, 'home_score' => 2, 'away_score' => 0, 'source' => 'admin'],
    ]);

    expect($fixture->result->source)->toBe('admin');
});

it('limits the activity log to the sportsbook logs and names the GMS staff member', function (): void {
    DB::connection('kadi_site')->table('activity_log')->insert([
        ['log_name' => 'odds', 'description' => 'Market suspended', 'properties' => json_encode(['staff' => ['id' => '7', 'name' => 'Jane Trader'], 'source' => 'gms'])],
        ['log_name' => 'player', 'description' => 'Signed in', 'properties' => null],
    ]);

    $entries = SiteActivity::query()->sportsbook()->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->staffLabel())->toBe('Jane Trader (GMS #7)');
});
