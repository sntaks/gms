<?php

use App\Filament\Pages\SportsbookJackpotDetailPage;
use App\Filament\Pages\SportsbookJackpotsPage;
use App\Models\AuditLog;
use App\Models\Site\SbCompetition;
use App\Models\Site\SbFixture;
use App\Models\Site\SbJackpot;
use App\Models\Site\SbJackpotEntry;
use App\Models\Site\SbJackpotFixture;
use App\Models\Site\SbSport;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\SiteSchema;

use function Pest\Laravel\get;

const JACKPOTS = 'https://kadi.test/api/v1/backoffice/sports/jackpots';

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    SiteSchema::create();
    useFakeSiteBackoffice();
    Http::preventStrayRequests();
    Sleep::fake();
});

function footballFixture(array $attributes = []): SbFixture
{
    $sport = SbSport::query()->where('slug', 'football')->first()
        ?? SbSport::factory()->create(['slug' => 'football', 'name' => 'Football']);
    $competition = SbCompetition::query()->where('sport_id', $sport->id)->first()
        ?? SbCompetition::factory()->create(['sport_id' => $sport->id, 'name' => 'Premier League']);

    return SbFixture::factory()->create(['competition_id' => $competition->id, ...$attributes]);
}

it('offers only upcoming football matches for a jackpot', function (): void {
    $upcoming = footballFixture(['home_team' => 'Arsenal', 'away_team' => 'Chelsea', 'kicks_off_at' => now()->addDay()]);
    $started = footballFixture(['kicks_off_at' => now()->subMinute()]);
    $cancelled = footballFixture(['kicks_off_at' => now()->addDay(), 'status' => 'cancelled']);
    $basketball = SbFixture::factory()->create(['kicks_off_at' => now()->addDay()]);

    $options = SportsbookJackpotsPage::fixtureOptions();

    expect(array_keys($options))->toBe([$upcoming->id])
        ->and($options[$upcoming->id])->toContain('Arsenal v Chelsea (Premier League)')
        ->and($options)->not->toHaveKeys([$started->id, $cancelled->id, $basketball->id]);
});

it('drafts a jackpot in cents and opens it', function (): void {
    signInWithRole('trader');
    $first = footballFixture(['kicks_off_at' => now()->addDay()]);
    $second = footballFixture(['kicks_off_at' => now()->addDays(2)]);
    Http::fake([JACKPOTS => Http::response(['status' => 'ok', 'data' => ['jackpot' => ['id' => 12, 'status' => 'draft']]], 201)]);

    Livewire::test(SportsbookJackpotsPage::class)
        ->callAction('createJackpot', data: [
            'name' => 'Weekend Mega Jackpot',
            'entry_price' => '99',
            'void_limit' => 3,
            'prize_tiers' => [['correct' => 2, 'pool' => '1000000'], ['correct' => 1, 'pool' => '50000.50']],
            'fixture_ids' => [$first->id, $second->id],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Draft saved')
        ->assertRedirect(SportsbookJackpotDetailPage::getUrl(['jackpot' => 12]));

    Http::assertSent(fn (Request $request): bool => siteAcceptsSignature($request)
        && $request['entry_price'] === 9900
        && $request['prize_tiers'] === [['correct' => 2, 'pool' => 100000000], ['correct' => 1, 'pool' => 5000050]]
        && $request['fixture_ids'] === [$first->id, $second->id]
        && $request['void_limit'] === 3);
});

it('puts the site\'s refusal on the matches field', function (): void {
    signInWithRole('trader');
    $fixture = footballFixture(['kicks_off_at' => now()->addDay()]);
    Http::fake([JACKPOTS => Http::response(['message' => 'Pick only matches that have not kicked off.', 'errors' => ['fixture_ids' => ['Pick only matches that have not kicked off.']]], 422)]);

    Livewire::test(SportsbookJackpotsPage::class)
        ->callAction('createJackpot', data: [
            'name' => 'Late jackpot', 'entry_price' => '50', 'void_limit' => 3,
            'prize_tiers' => [['correct' => 1, 'pool' => '1000']], 'fixture_ids' => [$fixture->id],
        ])
        ->assertHasActionErrors(['fixture_ids' => 'Pick only matches that have not kicked off.']);
});

it('edits a draft with its saved terms', function (): void {
    signInWithRole('trader');
    $jackpot = SbJackpot::factory()->create(['entry_price' => 9900, 'prize_tiers' => [['correct' => 1, 'pool' => 250000]]]);
    $fixture = footballFixture(['kicks_off_at' => now()->addDay()]);
    SbJackpotFixture::factory()->create(['jackpot_id' => $jackpot->id, 'fixture_id' => $fixture->id]);
    Http::fake([JACKPOTS."/{$jackpot->id}" => Http::response(['status' => 'ok', 'data' => ['jackpot' => ['id' => $jackpot->id]]])]);

    Livewire::test(SportsbookJackpotDetailPage::class, ['jackpot' => $jackpot->id])
        ->mountAction('editDraft')
        ->assertActionDataSet(['entry_price' => 99, 'fixture_ids' => [$fixture->id]])
        ->setActionData(['name' => 'Renamed jackpot'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Draft saved');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request['name'] === 'Renamed jackpot'
        && $request['entry_price'] === 9900
        && $request['prize_tiers'] === [['correct' => 1, 'pool' => 250000]]);
});

it('publishes a draft and shows why the site refused one', function (bool $accepted): void {
    signInWithRole('finance');
    $jackpot = SbJackpot::factory()->create(['name' => 'Weekend Mega Jackpot']);
    Http::fake([JACKPOTS."/{$jackpot->id}/publish" => $accepted
        ? Http::response(['status' => 'ok', 'data' => ['jackpot' => ['id' => $jackpot->id, 'status' => 'open']]])
        : Http::response(['message' => 'A jackpot needs between 13 and 17 matches; this one has 2.', 'errors' => ['jackpot' => ['A jackpot needs between 13 and 17 matches; this one has 2.']]], 422)]);

    $page = Livewire::test(SportsbookJackpotDetailPage::class, ['jackpot' => $jackpot->id])
        ->callAction('publishJackpot');

    $accepted
        ? $page->assertNotified('Weekend Mega Jackpot published')
        : $page->assertNotified(Notification::make()->title('Could not publish the jackpot')->body('A jackpot needs between 13 and 17 matches; this one has 2.')->danger());
    expect(AuditLog::query()->where('auditable_type', 'KadiSite\\SbJackpot')->sole()->event)->toBe('publish');
})->with([
    'accepted' => [true],
    'refused' => [false],
]);

it('cancels an open jackpot with a reason', function (): void {
    signInWithRole('finance');
    $jackpot = SbJackpot::factory()->create(['status' => 'open', 'name' => 'Weekend Mega Jackpot']);
    Http::fake([
        JACKPOTS."/{$jackpot->id}/tiers" => Http::response(['status' => 'ok', 'data' => ['tiers' => []]]),
        JACKPOTS."/{$jackpot->id}/cancel" => Http::response(['status' => 'ok', 'data' => ['jackpot' => ['id' => $jackpot->id, 'status' => 'cancelled']]]),
    ]);

    Livewire::test(SportsbookJackpotDetailPage::class, ['jackpot' => $jackpot->id])
        ->callAction('cancelJackpot', data: ['reason' => 'Three matches postponed'])
        ->assertHasNoActionErrors()
        ->assertNotified('Weekend Mega Jackpot cancelled');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cancel') && $request['reason'] === 'Three matches postponed');
});

it('shows each tier\'s winners and prize from the site for an open jackpot', function (): void {
    signInWithRole('manager');
    $jackpot = SbJackpot::factory()->create(['status' => 'open']);
    SbJackpotEntry::factory()->count(3)->create(['jackpot_id' => $jackpot->id, 'status' => 'open']);
    Http::fake([JACKPOTS."/{$jackpot->id}/tiers" => Http::response(['status' => 'ok', 'data' => ['tiers' => [
        ['correct' => 13, 'pool' => 100000000, 'winners' => 0, 'each' => 0],
        ['correct' => 12, 'pool' => 5000000, 'winners' => 4, 'each' => 1250000],
    ]]])]);

    get(SportsbookJackpotDetailPage::getUrl(['jackpot' => $jackpot->id]))
        ->assertSee('A preview until every match is decided.')
        ->assertSee('KES 12,500.00')
        ->assertSee('Open: 3');
});

it('splits drafting from publishing and cancelling', function (string $role, array $visible, array $hidden): void {
    signInWithRole($role);
    $jackpot = SbJackpot::factory()->create(['status' => 'draft']);

    $page = Livewire::test(SportsbookJackpotDetailPage::class, ['jackpot' => $jackpot->id]);

    foreach ($visible as $action) {
        $page->assertActionVisible($action);
    }

    foreach ($hidden as $action) {
        $page->assertActionHidden($action);
    }
})->with([
    'trader drafts' => ['trader', ['editDraft'], ['publishJackpot', 'cancelJackpot']],
    'finance publishes' => ['finance', ['publishJackpot', 'cancelJackpot'], ['editDraft']],
    'manager views' => ['manager', [], ['editDraft', 'publishJackpot', 'cancelJackpot']],
]);

it('offers neither edits nor cancelling once a jackpot has settled', function (): void {
    signInWithRole('admin');
    $jackpot = SbJackpot::factory()->create(['status' => 'settled']);
    Http::fake([JACKPOTS."/{$jackpot->id}/tiers" => Http::response(['status' => 'ok', 'data' => ['tiers' => []]])]);

    Livewire::test(SportsbookJackpotDetailPage::class, ['jackpot' => $jackpot->id])
        ->assertActionHidden('editDraft')
        ->assertActionHidden('publishJackpot')
        ->assertActionHidden('cancelJackpot');
});
