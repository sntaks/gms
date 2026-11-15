<?php

use App\Enums\UserStatus;
use App\Filament\Pages\SportsbookFixtureDetailPage;
use App\Models\AuditLog;
use App\Models\Site\SbFixture;
use App\Models\Site\SbFixtureResult;
use App\Models\Site\SbMarket;
use App\Models\Site\SbSelection;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\SiteSchema;

use function Pest\Laravel\get;

const BACKOFFICE = 'https://kadi.test/api/v1/backoffice/sports/';

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    SiteSchema::create();
    useFakeSiteBackoffice();
    Http::preventStrayRequests();
    Sleep::fake();
});

function signInAs(string $role): User
{
    $user = User::factory()->create(['status' => UserStatus::Active->value, 'name' => 'Jane Trader']);
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

/**
 * Arsenal v Chelsea with a three-way 1X2 market, kicked off 30 minutes ago.
 *
 * @return array{0: SbFixture, 1: SbMarket}
 */
function arsenalChelsea(string $marketStatus = 'open'): array
{
    $fixture = SbFixture::factory()->create(['home_team' => 'Arsenal', 'away_team' => 'Chelsea', 'kicks_off_at' => now()->subMinutes(30)]);
    $market = SbMarket::factory()->create([
        'fixture_id' => $fixture->id,
        'status' => $marketStatus,
        'suspension_reason' => $marketStatus === 'suspended' ? 'live-feed-stale' : null,
    ]);

    foreach ([['home', 'Arsenal', '2.10'], ['draw', 'Draw', '3.40'], ['away', 'Chelsea', '3.20']] as [$outcome, $name, $price]) {
        SbSelection::factory()->create(['market_id' => $market->id, 'outcome' => $outcome, 'name' => $name, 'feed_price' => $price]);
    }

    return [$fixture, $market];
}

function siteWrites(): AuditLog
{
    return AuditLog::query()->where('auditable_type', 'like', 'KadiSite%')->sole();
}

it('shows the markets with staff-readable labels, reasons and prices', function (): void {
    signInAs('trader');
    [$fixture] = arsenalChelsea('suspended');
    SbMarket::factory()->create(['fixture_id' => $fixture->id, 'type' => 'spreads', 'line' => '-1.50']);
    $winner = SbMarket::factory()->create(['fixture_id' => $fixture->id]);
    SbSelection::factory()->create(['market_id' => $winner->id, 'outcome' => 'home', 'name' => 'Arsenal', 'feed_price' => '1.80', 'override_price' => '1.75']);

    get(SportsbookFixtureDetailPage::getUrl(['fixture' => $fixture->id]))
        ->assertSee('1X2')
        ->assertSee('Live feed stale')
        ->assertSee('Handicap -1.5')
        ->assertSee('Winner')
        ->assertSee('Arsenal 1.75 (override, feed 1.80)');
});

it('suspends a market through the signed back office and audits it', function (): void {
    $trader = signInAs('trader');
    [$fixture, $market] = arsenalChelsea();
    Http::fake([BACKOFFICE."markets/{$market->id}/suspend" => Http::response(['status' => 'ok', 'data' => ['market' => $market->id, 'status' => 'suspended']])]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->callAction(TestAction::make('suspendMarket')->table($market), data: ['reason' => 'Injury news'])
        ->assertHasNoActionErrors()
        ->assertNotified('1X2 suspended');

    Http::assertSent(fn (Request $request): bool => siteAcceptsSignature($request)
        && $request['reason'] === 'Injury news'
        && $request['staff'] === ['id' => (string) $trader->id, 'name' => 'Jane Trader', 'email' => $trader->email]);
    expect(siteWrites())
        ->user_id->toBe($trader->id)
        ->event->toBe('suspend')
        ->auditable_id->toBe($market->id);
});

it('shows the site\'s reason when a market cannot be reopened', function (): void {
    signInAs('trader');
    [$fixture, $market] = arsenalChelsea('suspended');
    Http::fake([BACKOFFICE."markets/{$market->id}/reopen" => Http::response([
        'message' => 'Its odds are stale.',
        'code' => 'market_cannot_be_reopened',
    ], 409)]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->callAction(TestAction::make('reopenMarket')->table($market))
        ->assertNotified('Could not reopen the market');

    Http::assertSentCount(1);
    expect(siteWrites()->new_values['response']['code'])->toBe('market_cannot_be_reopened');
});

it('overrides some prices and clears the others', function (): void {
    signInAs('trader');
    [$fixture, $market] = arsenalChelsea();
    [$home, $draw, $away] = $market->selections()->orderBy('id')->get()->all();
    Http::fake([BACKOFFICE."markets/{$market->id}/prices" => Http::response(['status' => 'ok', 'data' => ['market' => $market->id, 'selections' => []]])]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->callAction(TestAction::make('overridePrices')->table($market), data: [
            'prices' => [$home->id => '1.9', $draw->id => '', $away->id => '3.25'],
            'reason' => 'Sharp money on Arsenal',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Prices saved');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"prices":{')
        && json_decode($request->body(), true)['prices'] === [$home->id => '1.90', $draw->id => null, $away->id => '3.25']
        && $request['reason'] === 'Sharp money on Arsenal');
});

it('refuses a price of 1.00 or less before calling the site', function (): void {
    signInAs('trader');
    [$fixture, $market] = arsenalChelsea();
    $home = $market->selections()->where('outcome', 'home')->sole();
    Http::fake();

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->callAction(TestAction::make('overridePrices')->table($market), data: ['prices' => [$home->id => '1.00'], 'reason' => 'Test'])
        ->assertHasActionErrors(["prices.{$home->id}" => 'A price must be above 1.00.']);

    Http::assertNothingSent();
});

it('records a result and reports what it settled', function (): void {
    $finance = signInAs('finance');
    [$fixture] = arsenalChelsea();
    Http::fake([BACKOFFICE."fixtures/{$fixture->id}/result" => Http::response(['status' => 'ok', 'data' => ['legs_decided' => 14, 'bets_settled' => 9]])]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->callAction('recordResult', data: ['home_score' => 2, 'away_score' => 1, 'half_time_home_score' => 1, 'half_time_away_score' => 1, 'note' => 'Checked on the broadcast'])
        ->assertHasNoActionErrors()
        ->assertNotified('Result recorded');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === [
        'home_score' => 2, 'away_score' => 1, 'half_time_home_score' => 1, 'half_time_away_score' => 1,
        'note' => 'Checked on the broadcast',
        'staff' => ['id' => (string) $finance->id, 'name' => 'Jane Trader', 'email' => $finance->email],
    ]);
    expect(siteWrites()->event)->toBe('result');
});

it('voids a match without scores', function (): void {
    signInAs('finance');
    [$fixture] = arsenalChelsea();
    Http::fake([BACKOFFICE."fixtures/{$fixture->id}/result" => Http::response(['status' => 'ok', 'data' => ['legs_decided' => 3, 'bets_settled' => 3]])]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->callAction('recordResult', data: ['is_void' => true, 'note' => 'Abandoned'])
        ->assertHasNoActionErrors()
        ->assertNotified('Match voided');

    Http::assertSent(fn (Request $request): bool => $request['is_void'] === true && ! isset($request['home_score']));
});

it('warns that a second result corrects the first and audits it as a correction', function (): void {
    signInAs('finance');
    [$fixture] = arsenalChelsea();
    SbFixtureResult::factory()->create(['fixture_id' => $fixture->id, 'home_score' => 1, 'away_score' => 1]);
    Http::fake([BACKOFFICE."fixtures/{$fixture->id}/result" => Http::response(['status' => 'ok', 'data' => ['legs_decided' => 2, 'bets_settled' => 2]])]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->mountAction('recordResult')
        ->assertActionMounted('recordResult')
        ->assertMountedActionModalSee(SportsbookFixtureDetailPage::CORRECTION_WARNING)
        ->setActionData(['home_score' => 2, 'away_score' => 1])
        ->callMountedAction();

    expect(siteWrites()->event)->toBe('result_correction');
});

it('puts the site\'s validation message on the score field', function (): void {
    signInAs('finance');
    [$fixture] = arsenalChelsea();
    Http::fake([BACKOFFICE."fixtures/{$fixture->id}/result" => Http::response([
        'message' => 'The home score may not be greater than 99.',
        'errors' => ['home_score' => ['The home score may not be greater than 99.']],
    ], 422)]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->callAction('recordResult', data: ['home_score' => 120, 'away_score' => 0])
        ->assertHasActionErrors(['home_score' => 'The home score may not be greater than 99.'])
        ->assertActionMounted('recordResult');
});

it('keeps the modal open with the same request id when the site does not answer', function (): void {
    signInAs('finance');
    [$fixture] = arsenalChelsea();
    Http::fakeSequence(BACKOFFICE."fixtures/{$fixture->id}/result")
        ->pushFailedConnection()
        ->pushFailedConnection()
        ->pushFailedConnection()
        ->push(['status' => 'ok', 'data' => ['legs_decided' => 1, 'bets_settled' => 1]]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->callAction('recordResult', data: ['home_score' => 1, 'away_score' => 0])
        ->assertNotified('Not confirmed yet')
        ->assertActionMounted('recordResult')
        ->callMountedAction()
        ->assertNotified('Result recorded');

    $requestIds = [];
    Http::assertSent(function (Request $request) use (&$requestIds): bool {
        $requestIds[] = $request->header('X-Backoffice-Request-Id')[0];

        return true;
    });
    expect($requestIds)->toHaveCount(4)
        ->and(array_unique($requestIds))->toHaveCount(1);
});

it('lets traders trade but not settle, and finance settle but not trade', function (): void {
    [$fixture, $market] = arsenalChelsea();

    signInAs('trader');
    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->assertActionVisible(TestAction::make('suspendMarket')->table($market))
        ->assertActionVisible(TestAction::make('overridePrices')->table($market))
        ->assertActionHidden('recordResult');

    signInAs('finance');
    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->assertActionHidden(TestAction::make('suspendMarket')->table($market))
        ->assertActionHidden(TestAction::make('overridePrices')->table($market))
        ->assertActionVisible('recordResult');
});

it('offers no result before kick-off', function (): void {
    signInAs('finance');
    $fixture = SbFixture::factory()->create(['kicks_off_at' => now()->addHour()]);

    Livewire::test(SportsbookFixtureDetailPage::class, ['fixture' => $fixture->id])
        ->assertActionHidden('recordResult');
});

it('returns not found for a fixture that does not exist', function (): void {
    signInAs('trader');

    get(SportsbookFixtureDetailPage::getUrl(['fixture' => 999]))->assertNotFound();
});
