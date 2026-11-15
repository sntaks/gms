<?php

use App\Enums\UserStatus;
use App\Filament\Pages;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

const ROLES = ['super-admin', 'admin', 'finance', 'manager', 'trader', 'agent'];

/**
 * Who may open each page this sync added (plans/20261115-gms-full-sync.md §7 and the
 * owner's decisions). A new page belongs here with a deliberate list of roles.
 *
 * @return array<class-string<Page>, list<string>>
 */
function syncedPageAccess(): array
{
    $sportsbook = ['super-admin', 'admin', 'finance', 'manager', 'trader'];
    $predict = ['super-admin', 'admin', 'finance', 'manager'];
    $games = ['super-admin', 'admin', 'finance', 'manager'];
    $financeReports = ['super-admin', 'admin', 'finance'];

    return [
        Pages\SportsbookFixturesPage::class => $sportsbook,
        Pages\SportsbookFixtureDetailPage::class => $sportsbook,
        Pages\SportsbookInPlayPage::class => $sportsbook,
        Pages\SportsbookCompetitionsPage::class => $sportsbook,
        Pages\SportsbookJackpotsPage::class => $sportsbook,
        Pages\SportsbookJackpotDetailPage::class => $sportsbook,
        Pages\SportsbookBetsPage::class => $sportsbook,
        Pages\SportsbookBetDetailPage::class => $sportsbook,
        Pages\SportsbookWagerOpsPage::class => $sportsbook,
        Pages\SportsbookExposurePage::class => $sportsbook,
        Pages\SportsbookActivityPage::class => $sportsbook,
        Pages\SportsbookReconciliationReport::class => $financeReports,
        Pages\PredictMarketsPage::class => $predict,
        Pages\PredictMarketDetailPage::class => $predict,
        Pages\PredictProposalsPage::class => $predict,
        Pages\PredictCorrectionsPage::class => $predict,
        Pages\PredictRiskEventsPage::class => $predict,
        Pages\PredictReportPage::class => $predict,
        Pages\PredictAuditPage::class => $predict,
        Pages\GamesPage::class => $games,
        Pages\GameProductsPage::class => $games,
        Pages\GameWagersPage::class => $games,
        Pages\GameWagerDetailPage::class => $games,
        Pages\GameKeysPage::class => ['super-admin'],
        Pages\HouseBankedReport::class => $financeReports,
        Pages\WinningsTaxReport::class => $financeReports,
        Pages\WinningsTaxChargesReport::class => $financeReports,
        Pages\WinningsTaxReturnsReport::class => $financeReports,
        Pages\WinningsTaxRemittancesReport::class => $financeReports,
        Pages\OpenWalletsReport::class => $financeReports,
        Pages\HouseFundingReport::class => $financeReports,
        Pages\ReferralSummaryReport::class => $financeReports,
        Pages\WithdrawalApprovalsPage::class => ['super-admin', 'admin', 'finance', 'manager'],
        Pages\ResponsibleGamblingFlagsPage::class => ['super-admin', 'admin', 'finance', 'manager', 'agent'],
        Pages\PlatformHealthPage::class => ['super-admin', 'admin', 'finance', 'manager'],
    ];
}

it('opens each synced page to exactly the roles decided for it', function (string $role): void {
    $user = User::factory()->create(['status' => UserStatus::Active->value]);
    $user->assignRole($role);
    $this->actingAs($user);

    $wrong = collect(syncedPageAccess())
        ->filter(fn (array $roles, string $page): bool => $page::canAccess() !== in_array($role, $roles, true))
        ->map(fn (array $roles, string $page): string => class_basename($page).' '.($page::canAccess() ? 'opens' : 'refuses'))
        ->values()
        ->all();

    expect($wrong)->toBe([]);
})->with(ROLES);

it('keeps the player access page to super-admins and admins', function (string $role, bool $allowed): void {
    $user = User::factory()->create(['status' => UserStatus::Active->value]);
    $user->assignRole($role);
    $this->actingAs($user);

    expect(Pages\AccessPage::canAccess())->toBe($allowed);
})->with([
    'super-admin' => ['super-admin', true],
    'admin' => ['admin', true],
    'manager' => ['manager', false],
    'agent' => ['agent', false],
]);

it('opens no staff page to a user without a role', function (): void {
    $this->actingAs(User::factory()->create(['status' => UserStatus::Active->value]));

    $open = collect(Filament::getDefaultPanel()->getPages())
        ->reject(fn (string $page): bool => in_array($page, roleFreePages(), true))
        ->filter(fn (string $page): bool => $page::canAccess())
        ->map(fn (string $page): string => class_basename($page))
        ->values()
        ->all();

    expect($open)->toBe([]);
});

/**
 * Pages every signed-in user may open whatever their role: the dashboard (its widgets gate
 * themselves) and the pages that show only the signed-in user's own referral codes.
 *
 * @return list<class-string<Page>>
 */
function roleFreePages(): array
{
    return [Dashboard::class, Pages\MyCustomers::class, Pages\MyPurchases::class];
}
