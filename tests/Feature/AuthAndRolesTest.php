<?php

use App\Enums\UserStatus;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

it('shows the filament login page at /login', function (): void {
    get('/login')->assertOk();
});

it('redirects unauthenticated users from / to /login', function (): void {
    get('/')->assertRedirectContains('login');
});

it('allows an active super-admin to log in via filament', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
        'status' => UserStatus::Active->value,
    ]);
    $user->assignRole('super-admin');

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->check())->toBeTrue();
});

it('allows an active agent to log in via filament', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
        'status' => UserStatus::Active->value,
    ]);
    $user->assignRole('agent');

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->check())->toBeTrue();
});

it('blocks a user with blocked status from accessing the panel', function (): void {
    $user = User::factory()->create([
        'status' => UserStatus::Blocked->value,
    ]);
    $user->assignRole('super-admin');

    $this->actingAs($user);

    get('/')->assertForbidden();
});

it('shows the profile page in the sidebar', function (): void {
    $user = User::factory()->create([
        'status' => UserStatus::Active->value,
    ]);
    $user->assignRole('super-admin');

    $this->actingAs($user)->get('/profile')->assertOk();
});

it('updates name and username via the profile page', function (): void {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'userName' => 'old_username',
        'status' => UserStatus::Active->value,
    ]);
    $user->assignRole('super-admin');

    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->set('data.name', 'New Name')
        ->set('data.userName', 'new_username')
        ->call('save');

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->userName)->toBe('new_username');
});

it('updates password via the profile page', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('OldPass@123'),
        'status' => UserStatus::Active->value,
    ]);
    $user->assignRole('super-admin');

    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->set('data.password', 'NewPass@123')
        ->set('data.passwordConfirmation', 'NewPass@123')
        ->set('data.currentPassword', 'OldPass@123')
        ->call('save');

    expect(Hash::check('NewPass@123', $user->fresh()->password))->toBeTrue();
});

it('gives the finance role money and settlement actions but no access management', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance');

    expect($finance->hasAllPermissions(['deposits.refund', 'bet-shops.float', 'referral-withdrawals.settle', 'sportsbook.settle', 'sportsbook.jackpots']))->toBeTrue()
        ->and($finance->hasAnyPermission(['users.create', 'roles.edit', 'permissions.edit', 'payouts.approve', 'sportsbook.trade']))->toBeFalse();
});

it('limits the trader role to sportsbook trading', function (): void {
    $trader = User::factory()->create();
    $trader->assignRole('trader');

    expect($trader->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe(['sportsbook.trade', 'sportsbook.view']);
});

it('keeps agents out of the sportsbook pages', function (): void {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    expect($agent->hasPermissionTo('sportsbook.view'))->toBeFalse();
});
