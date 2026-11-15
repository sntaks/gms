<?php

use App\Filament\Pages\SportsbookActivityPage;
use App\Filament\Pages\SportsbookExposurePage;
use App\Filament\Pages\Widgets\OddsApiCreditsWidget;
use App\Models\Site\SiteActivity;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\SiteSchema;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    SiteSchema::create();
    useFakeSiteBackoffice();
    Http::preventStrayRequests();
    Sleep::fake();
});

it('shows the exposure per match with its costliest outcome', function (): void {
    signInWithRole('trader');
    Http::fake(['https://kadi.test/api/v1/backoffice/sports/exposure?limit=100' => Http::response(['status' => 'ok', 'data' => ['matches' => [[
        'fixture_id' => 42, 'match' => 'Arsenal v Chelsea', 'competition' => 'Premier League', 'kicks_off_at' => '2026-11-21T12:30:00+00:00',
        'bets' => 31, 'stakes' => 1550000, 'worst_pick' => 'Arsenal', 'worst_payout' => 4210000,
        'picks' => [['pick' => 'Arsenal', 'bets' => 20, 'stakes' => 1000000, 'payout' => 4210000], ['pick' => 'Draw', 'bets' => 11, 'stakes' => 550000, 'payout' => 1870000]],
    ]]]])]);

    Livewire::test(SportsbookExposurePage::class)
        ->assertSee('Arsenal v Chelsea')
        ->assertSee('KES 42,100.00')
        ->assertSee('if Arsenal')
        ->assertSee('Sat 21 Nov, 15:30')
        ->assertSee('Draw: KES 18,700.00 on 11');
});

it('says why exposure is missing when the site refuses', function (): void {
    signInWithRole('trader');
    Http::fake(['https://kadi.test/api/v1/backoffice/sports/exposure?limit=100' => Http::response(['message' => 'The back-office API is not enabled.'], 503)]);

    Livewire::test(SportsbookExposurePage::class)
        ->assertSee('Exposure unavailable')
        ->assertSee('BACKOFFICE_GMS_SECRETS is empty on the site');
});

it('lists sportsbook activity with the GMS staff member, and no player activity', function (): void {
    signInWithRole('manager');
    DB::connection('kadi_site')->table('activity_log')->insert([
        ['log_name' => 'settlement', 'description' => 'Bet leg voided', 'properties' => json_encode(['staff' => ['id' => '7', 'name' => 'Jane Trader'], 'source' => 'gms', 'reason' => 'Priced in error'])],
        ['log_name' => 'odds', 'description' => 'Stale markets suspended', 'properties' => json_encode(['count' => 4])],
        ['log_name' => 'player', 'description' => 'Signed in', 'properties' => null],
    ]);

    Livewire::test(SportsbookActivityPage::class)
        ->assertSee('Bet leg voided')
        ->assertSee('Jane Trader (GMS #7)')
        ->assertSee('reason: Priced in error')
        ->assertSee('System')
        ->assertDontSee('Signed in');

    expect(SiteActivity::count())->toBe(3);
});

it('shows the Odds API credits left this month', function (): void {
    Http::fake(['https://kadi.test/api/v1/backoffice/sports/odds-api/credits' => Http::response(['status' => 'ok', 'data' => [
        'spent_this_month' => 8200, 'remaining' => 1800, 'monthly_cap' => 10000, 'reserve' => 500,
        'purposes' => ['live' => ['spent' => 3000, 'allowance' => 3000], 'scores' => ['spent' => 400, 'allowance' => null]],
    ]])]);

    $stats = collect((fn () => $this->getStats())->call(new OddsApiCreditsWidget))
        ->mapWithKeys(fn (Stat $stat): array => [$stat->getLabel() => [$stat->getValue(), $stat->getDescription(), $stat->getColor()]]);

    expect($stats['Remaining'])->toBe(['1,800', 'of 10,000 this month, 500 kept in reserve', 'success'])
        ->and($stats['Live'])->toBe(['3,000', 'of 3,000 allowed', 'warning'])
        ->and($stats['Scores'])->toBe(['400', 'only the monthly cap applies', 'gray']);
});
