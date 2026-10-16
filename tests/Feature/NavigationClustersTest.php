<?php

use App\Enums\UserStatus;
use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Filament\Clusters\GameReports\GameReportsCluster;
use App\Filament\Pages\CashFlowReport;
use App\Filament\Pages\GameIncomeReport;
use App\Filament\Pages\IncomeStatementReport;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\get;

/**
 * The sidebar of a page as rendered for the signed-in user.
 */
function sidebarHtml(): string
{
    $html = (string) get(Dashboard::getUrl())->assertOk()->getContent();
    $start = strpos($html, 'id="fi-main-sidebar"');

    expect($start)->not->toBeFalse();

    // The sidebar runs until the page's main content starts.
    $end = strpos($html, '<main', (int) $start);

    return substr($html, (int) $start, $end === false ? null : $end - (int) $start);
}

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);

    // The dashboard and reports read KadiApi; their figures do not matter here.
    Http::preventStrayRequests();
    Http::fake(['game-api.test/*' => Http::response(['success' => true, 'data' => []])]);

    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    $this->admin = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->admin->assignRole('admin');
});

it('shows one finance group with the two report clusters instead of every report', function (): void {
    $this->actingAs($this->admin);

    $sidebar = sidebarHtml();

    expect($sidebar)
        ->toContain('📊 Finance')
        ->toContain('Finance Reports')
        ->toContain('Game Reports')
        ->not->toContain('🧾 Finance Reports')
        ->not->toContain('📈 Reports')
        ->not->toContain('Cash Flow')
        ->not->toContain('Singles Leaderboard');

    expect(strpos($sidebar, 'Deposits'))->toBeLessThan(strpos($sidebar, 'Finance Reports'));
});

it('groups the finance reports in the cluster menu, overview first', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CashFlowReport::class)
        ->assertSeeInOrder([
            'Overview', 'Income Statement', 'Cash Flow', 'Balance Sheet', 'Trial Balance', 'Reconciliation',
            'Tax', 'Excise Duty', 'Excise Returns', 'Excise Charges', 'KRA Remittances', 'Taxes (Estimate)',
            'Activity', 'Ledger Explorer', 'Top Customers',
            'Promotions', 'Referral Bonuses', 'Signup Bonuses',
            'Bet Shops', 'Bet Shop Loads', 'Bet Shop Withdrawals',
        ]);
});

it('lets the main sidebar collapse on desktop and keeps the report menu a dropdown below 1536px', function (): void {
    $this->actingAs($this->admin);

    $html = (string) get(CashFlowReport::getUrl())->assertOk()->getContent();

    expect($html)
        ->toContain('fi-topbar-collapse-sidebar-btn-ctn')
        ->toContain('fi-page-sub-navigation-dropdown')
        ->toContain('@media (min-width: 48rem) and (max-width: 95.98rem)');
});

it('opens each cluster on its first report', function (): void {
    $this->actingAs($this->admin);

    get(FinanceReportsCluster::getUrl())->assertRedirect(IncomeStatementReport::getUrl());
    get(GameReportsCluster::getUrl())->assertRedirect(GameIncomeReport::getUrl());

    expect(CashFlowReport::getUrl())->toContain('/finance-reports/');
});

it('shows managers and agents the game reports but not the finance reports', function (string $role): void {
    $user = User::factory()->create(['status' => UserStatus::Active->value]);
    $user->assignRole($role);
    $this->actingAs($user);

    expect(sidebarHtml())
        ->toContain('Game Reports')
        ->not->toContain('Finance Reports');

    get(FinanceReportsCluster::getUrl())->assertForbidden();
})->with(['manager', 'agent']);
