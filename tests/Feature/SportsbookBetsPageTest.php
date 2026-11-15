<?php

use App\Filament\Pages\SportsbookBetDetailPage;
use App\Filament\Pages\SportsbookBetsPage;
use App\Models\AuditLog;
use App\Models\Site\SbBet;
use App\Models\Site\SbBetLeg;
use App\Models\Site\SiteUser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\SiteSchema;

use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    SiteSchema::create();
    useFakeSiteBackoffice();
    Http::preventStrayRequests();
    Sleep::fake();
});

it('finds a bet by reference, wager reference, player name or customer number', function (string $search): void {
    signInWithRole('manager');
    $player = SiteUser::factory()->create(['name' => 'Wanjiku Kamau', 'linked_id' => 5501]);
    $bet = SbBet::factory()->create(['user_id' => $player->id, 'reference' => 'KB7Q2X9A', 'wager_reference' => '0b9a6f6e-3c2d-4c1e-9d4a-6a1e2f3b4c5d']);
    $other = SbBet::factory()->create();

    Livewire::test(SportsbookBetsPage::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$bet])
        ->assertCanNotSeeTableRecords([$other]);
})->with([
    'bet reference' => ['7Q2X'],
    'wager reference' => ['0b9a6f6e-3c2d-4c1e-9d4a-6a1e2f3b4c5d'],
    'player name' => ['Wanjiku'],
    'customer number' => ['#5501'],
]);

it('tells in-play bets apart from pre-match ones', function (): void {
    signInWithRole('manager');
    $live = SbBet::factory()->create(['product' => 'sportsbook_live']);
    $preMatch = SbBet::factory()->create(['product' => null]);

    Livewire::test(SportsbookBetsPage::class)
        ->filterTable('product', 'sportsbook_live')
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$preMatch]);
});

it('keeps agents out of the bets', function (): void {
    signInWithRole('agent');

    get(SportsbookBetsPage::getUrl())->assertForbidden();
});

it('voids a leg and says what became of the bet', function (): void {
    signInWithRole('finance');
    $bet = SbBet::factory()->create(['status' => 'lost']);
    $leg = SbBetLeg::factory()->create(['bet_id' => $bet->id, 'status' => 'lost', 'fixture_name' => 'Arsenal v Chelsea']);
    Http::fake(["https://kadi.test/api/v1/backoffice/sports/bet-legs/{$leg->id}/void" => Http::response(['status' => 'ok', 'data' => [
        'leg' => ['id' => $leg->id, 'status' => 'void'],
        'bet' => ['id' => $bet->id, 'status' => 'won'],
    ]])]);

    Livewire::test(SportsbookBetDetailPage::class, ['bet' => $bet->id])
        ->callAction(TestAction::make('voidLeg')->table($leg), data: ['reason' => 'Priced in error'])
        ->assertHasNoActionErrors()
        ->assertNotified('Leg voided');

    Http::assertSent(fn (Request $request): bool => siteAcceptsSignature($request) && $request['reason'] === 'Priced in error');
    expect(AuditLog::query()->where('auditable_type', 'KadiSite\\SbBetLeg')->sole()->event)->toBe('void');
});

it('cancels an open bet', function (): void {
    signInWithRole('finance');
    $bet = SbBet::factory()->create(['status' => 'open', 'reference' => 'KB7Q2X9A']);
    Http::fake(["https://kadi.test/api/v1/backoffice/sports/bets/{$bet->id}/cancel" => Http::response(['status' => 'ok', 'data' => ['bet' => ['id' => $bet->id, 'status' => 'cancelled']]])]);

    Livewire::test(SportsbookBetDetailPage::class, ['bet' => $bet->id])
        ->callAction('cancelBet', data: ['reason' => 'Placed after the goal'])
        ->assertHasNoActionErrors()
        ->assertNotified('Bet KB7Q2X9A cancelled');

    Http::assertSent(fn (Request $request): bool => $request['reason'] === 'Placed after the goal');
});

it('offers cancelling only on open bets and only to settlers', function (string $role, string $status, bool $visible): void {
    signInWithRole($role);
    $bet = SbBet::factory()->create(['status' => $status]);

    $page = Livewire::test(SportsbookBetDetailPage::class, ['bet' => $bet->id]);

    $visible ? $page->assertActionVisible('cancelBet') : $page->assertActionHidden('cancelBet');
})->with([
    'finance, open' => ['finance', 'open', true],
    'finance, won' => ['finance', 'won', false],
    'trader, open' => ['trader', 'open', false],
]);

it('offers no void on a leg already void or a bet that never stood', function (string $betStatus, string $legStatus): void {
    signInWithRole('finance');
    $bet = SbBet::factory()->create(['status' => $betStatus]);
    $leg = SbBetLeg::factory()->create(['bet_id' => $bet->id, 'status' => $legStatus]);

    Livewire::test(SportsbookBetDetailPage::class, ['bet' => $bet->id])
        ->assertActionHidden(TestAction::make('voidLeg')->table($leg));
})->with([
    'leg already void' => ['open', 'void'],
    'bet rejected' => ['rejected', 'open'],
    'bet cancelled' => ['cancelled', 'open'],
]);
