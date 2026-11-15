<?php

use App\Filament\Pages\SportsbookFixturesPage;
use App\Models\Site\SbFixture;
use App\Models\Site\SbFixtureResult;
use App\Models\Site\SbMarket;
use Livewire\Livewire;
use Tests\Support\SiteSchema;

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    SiteSchema::create();
});

it('opens the fixtures to sportsbook viewers and keeps other staff out', function (string $role, bool $allowed): void {
    signInWithRole($role);

    $response = get(SportsbookFixturesPage::getUrl());

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'trader' => ['trader', true],
    'finance' => ['finance', true],
    'manager' => ['manager', true],
    'agent' => ['agent', false],
]);

it('sorts fixtures into upcoming, live, awaiting result, resulted and cancelled', function (): void {
    freezeTime();
    signInWithRole('trader');
    $upcoming = SbFixture::factory()->create(['kicks_off_at' => now()->addHours(2)]);
    $live = SbFixture::factory()->create(['kicks_off_at' => now()->subMinutes(30)]);
    $feedDone = SbFixture::factory()->create(['kicks_off_at' => now()->subHours(2), 'feed_completed_at' => now()->subMinutes(5)]);
    $longOver = SbFixture::factory()->create(['kicks_off_at' => now()->subHours(5)]);
    $resulted = SbFixture::factory()->create(['kicks_off_at' => now()->subHours(3)]);
    SbFixtureResult::factory()->create(['fixture_id' => $resulted->id]);
    $cancelled = SbFixture::factory()->create(['kicks_off_at' => now()->addDay(), 'status' => 'cancelled']);
    $all = [$upcoming, $live, $feedDone, $longOver, $resulted, $cancelled];

    $page = Livewire::test(SportsbookFixturesPage::class);

    foreach ([
        'upcoming' => [$upcoming],
        'live' => [$live],
        'awaiting_result' => [$feedDone, $longOver],
        'resulted' => [$resulted],
        'cancelled' => [$cancelled],
    ] as $tab => $expected) {
        $page->call('setTab', $tab)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords(array_udiff($all, $expected, fn (SbFixture $a, SbFixture $b): int => $a->id <=> $b->id));
    }
});

it('shows the score and how many markets are open and suspended', function (): void {
    signInWithRole('trader');
    $fixture = SbFixture::factory()->create([
        'home_team' => 'Arsenal', 'away_team' => 'Chelsea',
        'kicks_off_at' => now()->subMinutes(20), 'home_score' => 1, 'away_score' => 0,
    ]);
    SbMarket::factory()->count(2)->create(['fixture_id' => $fixture->id, 'status' => 'open']);
    SbMarket::factory()->create(['fixture_id' => $fixture->id, 'status' => 'suspended', 'suspension_reason' => 'score-changed']);

    Livewire::test(SportsbookFixturesPage::class)
        ->call('setTab', 'live')
        ->assertTableColumnStateSet('score', '1 – 0', $fixture)
        ->assertTableColumnStateSet('open_markets_count', 2, $fixture)
        ->assertTableColumnStateSet('suspended_markets_count', 1, $fixture);
});
