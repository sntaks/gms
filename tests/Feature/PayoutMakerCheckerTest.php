<?php

use App\Enums\PayoutStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Models\Expense;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    $this->maker = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->maker->assignRole('super-admin');

    $this->checker = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->checker->assignRole('super-admin');

    $this->payee = Payee::create(['name' => 'Achieng Otieno', 'phone' => '254712345678', 'designation' => 'Accountant', 'team' => 'Finance']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makePayout(User $maker, array $attributes = []): Payout
{
    test()->actingAs($maker);

    return Payout::create([
        'payee_id' => test()->payee->id,
        'amount' => 1500,
        'reason' => 'Monthly bookkeeping fee',
        ...$attributes,
    ]);
}

it('records the logged-in user as the payout maker', function (): void {
    $payout = makePayout($this->maker);

    expect($payout->created_by)->toBe($this->maker->id);
});

it('hides approve from the maker of the payout', function (): void {
    $payout = makePayout($this->maker);

    Livewire::test(ListPayouts::class)
        ->assertActionHidden(TestAction::make('approve')->table($payout));
});

it('lets a different super-admin approve the payout', function (): void {
    $payout = makePayout($this->maker);
    $this->actingAs($this->checker);

    Livewire::test(ListPayouts::class)
        ->callAction(TestAction::make('approve')->table($payout))
        ->assertNotified('Payout approved — queued for M-Pesa processing');

    expect($payout->fresh()->status)->toBe(PayoutStatus::Approved)
        ->and($payout->fresh()->approved_by)->toBe($this->checker->id);
});

it('does not let admins or managers approve payouts', function (string $role): void {
    $payout = makePayout($this->maker);
    $user = User::factory()->create(['status' => UserStatus::Active->value]);
    $user->assignRole($role);
    $this->actingAs($user);

    Livewire::test(ListPayouts::class)
        ->assertActionHidden(TestAction::make('approve')->table($payout));

    expect($user->can('approve', $payout))->toBeFalse();
})->with(['admin', 'manager']);

it('skips the makers own payouts when bulk approving', function (): void {
    $own = makePayout($this->maker);
    $other = makePayout($this->checker);
    $this->actingAs($this->maker);

    Livewire::test(ListPayouts::class)
        ->selectTableRecords([$own, $other])
        ->callAction(TestAction::make('bulk_approve')->table()->bulk())
        ->assertNotified('1 payout(s) approved');

    expect($own->fresh()->status)->toBe(PayoutStatus::Pending)
        ->and($other->fresh()->status)->toBe(PayoutStatus::Approved)
        ->and(Expense::count())->toBe(1);
});

it('applies maker-checker to retrying a failed payout', function (): void {
    $payout = makePayout($this->maker, ['status' => PayoutStatus::Failed]);

    Livewire::test(ListPayouts::class)
        ->assertActionHidden(TestAction::make('retry')->table($payout));

    $this->actingAs($this->checker);

    Livewire::test(ListPayouts::class)
        ->callAction(TestAction::make('retry')->table($payout));

    expect($payout->fresh()->status)->toBe(PayoutStatus::Approved);
});

it('refuses approval when the payout maker is unknown', function (): void {
    $payout = makePayout($this->maker);
    $payout->updateQuietly(['created_by' => null]);

    expect($this->checker->can('approve', $payout->fresh()))->toBeFalse();
});

it('still lets the maker decline their own payout', function (): void {
    $payout = makePayout($this->maker);

    Livewire::test(ListPayouts::class)
        ->callAction(TestAction::make('decline')->table($payout), data: ['declined_reason' => 'Raised in error'])
        ->assertHasNoActionErrors();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Declined);
});
