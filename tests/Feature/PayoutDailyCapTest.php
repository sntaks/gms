<?php

use App\Enums\ExpenseCategory;
use App\Enums\PayoutStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Payouts\PayoutResource;
use App\Models\Expense;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    config(['payouts.daily_cap' => 1000]);
    $this->travelTo(now()->setTime(12, 0));

    $this->maker = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->maker->assignRole('super-admin');

    $this->checker = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->checker->assignRole('super-admin');

    $this->payee = Payee::create(['name' => 'Wanjiru Kamau', 'phone' => '254711222333', 'designation' => 'Driver', 'team' => 'Operations']);

    $this->actingAs($this->maker);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeCappedPayout(float $amount, array $attributes = []): Payout
{
    return Payout::create([
        'payee_id' => test()->payee->id,
        'amount' => $amount,
        'reason' => 'Fuel refund',
        ...$attributes,
    ]);
}

it('lets the maker approve their own payout within the daily cap', function (): void {
    $payout = makeCappedPayout(400);

    Livewire::test(ListPayouts::class)
        ->callAction(TestAction::make('approve')->table($payout))
        ->assertNotified('Payout approved — queued for M-Pesa processing');

    expect($payout->fresh())
        ->requires_independent_approval->toBeFalse()
        ->status->toBe(PayoutStatus::Approved)
        ->approved_by->toBe($this->maker->id);
});

it('requires a different approver when a single payout exceeds the cap', function (): void {
    $payout = makeCappedPayout(1000.01);

    Livewire::test(ListPayouts::class)
        ->assertActionHidden(TestAction::make('approve')->table($payout));

    expect($payout->requires_independent_approval)->toBeTrue()
        ->and($this->checker->can('approve', $payout))->toBeTrue();
});

it('requires a different approver when the payout pushes the daily total past the cap', function (): void {
    $first = makeCappedPayout(600);
    $second = makeCappedPayout(400);
    $third = makeCappedPayout(1);

    expect($first->fresh()->requires_independent_approval)->toBeFalse()
        ->and($second->requires_independent_approval)->toBeFalse()
        ->and($third->requires_independent_approval)->toBeTrue()
        ->and($this->maker->can('approve', $third))->toBeFalse();
});

it('ignores declined payouts and payouts from previous days', function (): void {
    makeCappedPayout(900, ['status' => PayoutStatus::Declined]);

    $this->travelTo(now()->subDay()->setTime(23, 59));
    makeCappedPayout(900);
    $this->travelTo(now()->addDay()->setTime(0, 1));

    expect(makeCappedPayout(1000)->requires_independent_approval)->toBeFalse();
});

it('applies the cap rule to retrying a failed payout', function (): void {
    $withinCap = makeCappedPayout(300, ['status' => PayoutStatus::Failed]);
    $overCap = makeCappedPayout(1200, ['status' => PayoutStatus::Failed]);

    Livewire::test(ListPayouts::class)
        ->assertActionVisible(TestAction::make('retry')->table($withinCap))
        ->assertActionHidden(TestAction::make('retry')->table($overCap));
});

it('still blocks admins from approving their own payouts within the cap', function (): void {
    $admin = User::factory()->create(['status' => UserStatus::Active->value]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $payout = makeCappedPayout(100);

    expect($payout->requires_independent_approval)->toBeFalse()
        ->and($admin->can('approve', $payout))->toBeFalse();
});

it('warns in the create form when the payout would exceed the cap', function (): void {
    makeCappedPayout(700);

    Livewire::test(ListPayouts::class)
        ->mountAction('create')
        ->fillForm(['amount' => 300])
        ->assertSchemaComponentHidden('dailyCapWarning')
        ->fillForm(['amount' => 301])
        ->assertSchemaComponentVisible('dailyCapWarning');
});

it('creates payouts from the form with the cap decision recorded', function (): void {
    Livewire::test(ListPayouts::class)
        ->callAction('create', data: ['payee_id' => $this->payee->id, 'amount' => 1500, 'reason' => 'Vehicle repairs'])
        ->assertHasNoActionErrors();

    expect(Payout::sole())
        ->requires_independent_approval->toBeTrue()
        ->created_by->toBe($this->maker->id);
});

it('shows the pending payout count as a danger navigation badge', function (): void {
    makeCappedPayout(100);
    makeCappedPayout(200);
    makeCappedPayout(300, ['status' => PayoutStatus::Failed]);

    expect(PayoutResource::getNavigationBadge())->toBe('2')
        ->and(PayoutResource::getNavigationBadgeColor())->toBe('danger');
});

it('hides the navigation badge when nothing is pending', function (): void {
    makeCappedPayout(100, ['status' => PayoutStatus::Completed]);

    expect(PayoutResource::getNavigationBadge())->toBeNull();
});

it('declines a failed payout with a reason and removes its booked expense', function (): void {
    $expense = Expense::create(['category' => ExpenseCategory::Expense, 'amount' => 500, 'description' => 'Payout to Wanjiru Kamau']);
    $payout = makeCappedPayout(500, ['status' => PayoutStatus::Failed, 'expense_id' => $expense->id]);

    Livewire::test(ListPayouts::class)
        ->callAction(TestAction::make('decline_failed')->table($payout), data: ['declined_reason' => 'Wrong phone number'])
        ->assertHasNoActionErrors()
        ->assertNotified('Failed payout declined');

    expect($payout->fresh())
        ->status->toBe(PayoutStatus::Declined)
        ->declined_reason->toBe('Wrong phone number')
        ->declined_by->toBe($this->maker->id)
        ->expense_id->toBeNull()
        ->and(Expense::find($expense->id))->toBeNull();
});

it('requires a reason to decline a failed payout', function (): void {
    $payout = makeCappedPayout(500, ['status' => PayoutStatus::Failed]);

    Livewire::test(ListPayouts::class)
        ->callAction(TestAction::make('decline_failed')->table($payout), data: ['declined_reason' => ''])
        ->assertHasActionErrors(['declined_reason' => 'required']);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Failed);
});

it('hides decline failed on non-failed payouts', function (): void {
    $pending = makeCappedPayout(100);

    Livewire::test(ListPayouts::class)
        ->assertActionHidden(TestAction::make('decline_failed')->table($pending));
});

it('does not let users without the decline permission decline failed payouts', function (): void {
    $failed = makeCappedPayout(100, ['status' => PayoutStatus::Failed]);

    $agent = User::factory()->create(['status' => UserStatus::Active->value]);
    $agent->assignRole('agent');

    expect($agent->can('decline', $failed))->toBeFalse();
});

it('shows payout details in the view action', function (): void {
    $payout = makeCappedPayout(250);

    Livewire::test(ListPayouts::class)
        ->mountAction(TestAction::make('view')->table($payout))
        ->assertSee('Wanjiru Kamau')
        ->assertSee('Fuel refund')
        ->assertSee('Self-approvable');
});
