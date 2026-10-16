<?php

use App\Enums\UserStatus;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * Fake the customer KadiApi calls the customer list and page make, for customer 900.
 */
function fakeCustomerShopActivityApi(): void
{
    Http::preventStrayRequests();

    Http::fake([
        '*/customers/transactions/*' => Http::response(['total' => 2950, 'transactions' => [
            ['id' => 310, 'payment_type' => 'App\\Models\\Deposit', 'amount' => '95.00', 'created_at' => '2026-10-15T09:52:10.000000Z'],
            ['id' => 311, 'payment_type' => 'App\\Models\\BetShopLoad', 'amount' => '950.00', 'created_at' => '2026-10-16T07:15:00.000000Z'],
            ['id' => 312, 'payment_type' => 'App\\Models\\ShopWithdrawal', 'amount' => '1000.00', 'created_at' => '2026-10-16T08:00:00.000000Z'],
        ]]),
        '*/customers/purchases/*' => Http::response(['data' => []]),
        '*/customers/*' => Http::response(['data' => [
            'id' => 900, 'account_no' => 'KK-8FAV7WJY', 'player_no' => '12345671', 'name' => 'Grace Wanjiku Kamau', 'phone_no' => '254712345678',
            'email' => 'grace@example.com', 'status' => 1, 'wallet_id' => 900, 'balance' => '1500.00',
            'deposits' => 2000, 'withdraws' => 500, 'shop_loads' => 950, 'shop_withdrawals' => 1000,
        ]]),
        '*/customers' => Http::response(['data' => [
            ['id' => 900, 'player_no' => '12345671', 'name' => 'Grace Wanjiku Kamau', 'phone_no' => '254712345678', 'email' => 'grace@example.com', 'balance' => '1500.00', 'status' => 1],
            ['id' => 901, 'player_no' => '87654321', 'name' => 'Otieno Juma', 'phone_no' => '254798765432', 'email' => 'otieno@example.com', 'balance' => '0.00', 'status' => 1],
        ]]),
    ]);
}

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.game_api.url' => 'https://game-api.test',
        'services.game_api.key' => 'test-api-key',
        'services.game_api.openssl_key' => 'a9378f354771d2bdf46c1fd1b5bcaf38',
    ]);

    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    $this->manager = User::factory()->create(['status' => UserStatus::Active->value]);
    $this->manager->assignRole('manager');
});

it('shows the player number and the M-Pesa and shop totals apart', function (): void {
    fakeCustomerShopActivityApi();
    $this->actingAs($this->manager);

    Livewire::test(ViewAccount::class, ['record' => 900])
        ->assertSee('1234 5671')
        ->assertSee('M-Pesa Deposits')
        ->assertSee('KES 2,000.00')
        ->assertSee('Shop Loads')
        ->assertSee('KES 950.00')
        ->assertSee('Shop Withdrawals')
        ->assertSee('KES 1,000.00')
        ->assertDontSee('Lifetime Deposits');
});

it('labels shop loads and shop withdrawals in the transaction tabs', function (): void {
    fakeCustomerShopActivityApi();
    $this->actingAs($this->manager);

    Livewire::test(ViewAccount::class, ['record' => 900])
        ->assertSet('deposits', fn (array $deposits): bool => count($deposits) === 2)
        ->assertSet('withdrawals', fn (array $withdrawals): bool => count($withdrawals) === 1)
        ->assertSee('M-Pesa deposit')
        ->assertSee('Shop load')
        ->assertSee('Shop withdrawal');
});

it('shows the shop tabs only to users who can view bet shops', function (): void {
    fakeCustomerShopActivityApi();
    $this->actingAs($this->manager);

    Livewire::test(ViewAccount::class, ['record' => 900])
        ->assertSee('Shop loads')
        ->assertSee('Shop withdrawals');

    $support = User::factory()->create(['status' => UserStatus::Active->value]);
    $support->givePermissionTo(Permission::findByName('accounts.view'), Permission::findByName('account.view'));
    $this->actingAs($support);

    Livewire::test(ViewAccount::class, ['record' => 900])
        ->assertDontSee('Shop withdrawals');
});

it('lists player numbers and finds a player by the number as typed', function (): void {
    fakeCustomerShopActivityApi();
    $this->actingAs($this->manager);

    Livewire::test(ListAccounts::class)
        ->assertSee('1234 5671')
        ->searchTable('1234 5671')
        ->assertSee('Grace Wanjiku Kamau')
        ->assertDontSee('Otieno Juma');
});
