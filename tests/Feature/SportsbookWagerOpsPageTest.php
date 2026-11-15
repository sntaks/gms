<?php

use App\Filament\Pages\SportsbookWagerOpsPage;
use App\Models\Site\SbWagerOp;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\SiteSchema;

use function Pest\Laravel\freezeTime;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    SiteSchema::create();
    useFakeSiteBackoffice();
    Http::preventStrayRequests();
    Sleep::fake();
});

it('lists failed ops and ops pending over ten minutes as needing attention', function (): void {
    freezeTime();
    signInWithRole('finance');
    $failed = SbWagerOp::factory()->create(['status' => 'failed', 'last_status' => 500, 'last_error' => 'Server Error']);
    $overdue = SbWagerOp::factory()->create(['status' => 'pending', 'created_at' => now()->subMinutes(11)]);
    $recent = SbWagerOp::factory()->create(['status' => 'pending', 'created_at' => now()->subMinutes(9)]);
    $sent = SbWagerOp::factory()->create(['status' => 'sent', 'created_at' => now()->subHour()]);

    Livewire::test(SportsbookWagerOpsPage::class)
        ->assertCanSeeTableRecords([$failed, $overdue])
        ->assertCanNotSeeTableRecords([$recent, $sent])
        ->assertTableColumnStateSet('last_error', 'HTTP 500 Server Error', $failed);

    expect(SportsbookWagerOpsPage::navigationBadgeCount())->toBe(2);
});

it('retries a failed op through the signed back office', function (): void {
    signInWithRole('finance');
    $op = SbWagerOp::factory()->create(['status' => 'failed']);
    Http::fake(["https://kadi.test/api/v1/backoffice/sports/wager-ops/{$op->id}/retry" => Http::response(['status' => 'ok', 'data' => ['op' => $op->id, 'status' => 'pending']])]);

    Livewire::test(SportsbookWagerOpsPage::class)
        ->callAction(TestAction::make('retryOp')->table($op))
        ->assertNotified('Op queued again');

    Http::assertSent(fn (Request $request): bool => siteAcceptsSignature($request) && array_keys($request->data()) === ['staff']);
});

it('shows the site\'s refusal when the op is no longer failed', function (): void {
    signInWithRole('finance');
    $op = SbWagerOp::factory()->create(['status' => 'failed']);
    Http::fake(["https://kadi.test/api/v1/backoffice/sports/wager-ops/{$op->id}/retry" => Http::response(['message' => 'Only a failed op can be retried.', 'code' => 'op_not_failed'], 409)]);

    Livewire::test(SportsbookWagerOpsPage::class)
        ->callAction(TestAction::make('retryOp')->table($op))
        ->assertNotified('Could not retry the op');
});

it('offers retry only on failed ops and only to settlers', function (string $role, string $status, bool $visible): void {
    signInWithRole($role);
    $op = SbWagerOp::factory()->create(['status' => $status, 'created_at' => now()->subHour()]);

    $page = Livewire::test(SportsbookWagerOpsPage::class)->call('setTab', 'all');

    $visible
        ? $page->assertActionVisible(TestAction::make('retryOp')->table($op))
        : $page->assertActionHidden(TestAction::make('retryOp')->table($op));
})->with([
    'finance, failed' => ['finance', 'failed', true],
    'finance, pending' => ['finance', 'pending', false],
    'trader, failed' => ['trader', 'failed', false],
]);

it('leaves the sidebar working when the site database cannot be read', function (): void {
    signInWithRole('finance');
    SbWagerOp::factory()->create(['status' => 'failed']);
    expect(SportsbookWagerOpsPage::getNavigationBadge())->toBe('1');
    config(['database.connections.kadi_site.database' => '/nonexistent/kadi.sqlite']);
    DB::purge('kadi_site');

    $badge = SportsbookWagerOpsPage::getNavigationBadge();

    expect($badge)->toBeNull();
});
