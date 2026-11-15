<?php

use App\Enums\UserStatus;
use App\Filament\Pages\SportsbookCompetitionsPage;
use App\Models\Site\SbCompetition;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
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

function competitionsAs(string $role): void
{
    $user = User::factory()->create(['status' => UserStatus::Active->value]);
    $user->assignRole($role);
    test()->actingAs($user);
}

it('saves a competition\'s settings through the signed back office', function (): void {
    competitionsAs('trader');
    $competition = SbCompetition::factory()->create(['name' => 'Premier League', 'is_synced' => true, 'auto_settle' => true, 'sort_order' => 10]);
    Http::fake(["https://kadi.test/api/v1/backoffice/sports/competitions/{$competition->id}" => Http::response(['status' => 'ok', 'data' => ['competition' => []]])]);

    Livewire::test(SportsbookCompetitionsPage::class)
        ->callAction(TestAction::make('editSettings')->table($competition), data: ['is_synced' => true, 'auto_settle' => false, 'sort_order' => 5])
        ->assertHasNoActionErrors()
        ->assertNotified('Premier League settings saved');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && siteAcceptsSignature($request)
        && $request['is_synced'] === true
        && $request['auto_settle'] === false
        && $request['sort_order'] === 5);
});

it('shows competitions to managers without the settings action', function (): void {
    competitionsAs('manager');
    $competition = SbCompetition::factory()->create();

    Livewire::test(SportsbookCompetitionsPage::class)
        ->assertCanSeeTableRecords([$competition])
        ->assertActionHidden(TestAction::make('editSettings')->table($competition));
});
