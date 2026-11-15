<?php

use App\Models\User;
use App\Support\StaffIdentity;

it('names a staff member to the site by GMS id, name and email', function (): void {
    $user = User::factory()->create(['id' => 17, 'name' => 'Jane Trader', 'email' => 'jane@example.com']);

    expect(StaffIdentity::forSite($user))->toBe(['id' => '17', 'name' => 'Jane Trader', 'email' => 'jane@example.com']);
});

it('leaves out an email the site would refuse', function (): void {
    $user = User::factory()->make(['id' => 17, 'name' => 'Jane Trader', 'email' => str_repeat('a', 140).'@example.com']);

    expect(StaffIdentity::forSite($user))->not->toHaveKey('email');
});

it('shortens a long name for the Kadi API but keeps the GMS id', function (): void {
    $user = User::factory()->make(['id' => 17, 'name' => str_repeat('N', 120)]);

    $adminUser = StaffIdentity::adminUser($user);

    expect($adminUser)->toHaveLength(100)
        ->toEndWith(' (GMS #17)');
});
