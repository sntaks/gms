<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn(PHP_EOL.'Creating Roles & Permissions...');

        $permissions = [
            // Players
            'accounts.view',
            'accounts.ban',
            'account.view',
            'account.edit',
            'account.wallet.edit',
            'complaints.view',
            'complaints.close',
            'referral-withdrawals.settle',
            'referral-codes.edit',

            // Promotions
            'promo-codes.manage',

            // Bet Shops
            'bet-shops.view',
            'bet-shops.manage',
            'bet-shops.float',

            // Shareholders
            'holders.view',
            'holders.create',
            'holders.edit',
            'holders.delete',
            'dependants.view',
            'dependants.create',
            'dependants.edit',
            'dependants.delete',
            'withdrawals.view',

            // Expenses & Payouts
            'expenses.view',
            'expenses.create',
            'expenses.edit',
            'expenses.delete',
            'payouts.view',
            'payouts.create',
            'payouts.approve',
            'payouts.decline',
            'payees.view',
            'payees.create',
            'payees.edit',
            'payees.delete',

            // Financial
            'company-withdrawals.view',
            'company-withdrawals.create',
            'company-withdrawals.approve',
            'wallet-transactions.view',
            'income-distributions.view',
            'api-income-logs.view',
            'deposits.view',
            'deposits.assign',
            'deposits.refund',
            'deposits.match',

            // Game Results
            'game-results.view',
            'jackpot-results.view',
            'tournament-results.view',

            // Reports
            'reports.view',
            'excise-duty.remit',

            // Access Management
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            'permissions.view',
            'permissions.create',
            'permissions.edit',
            'permissions.delete',

            // Administration
            'audit-logs.view',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission]);
        }

        $allPermissions = Permission::all();

        $managerPermissions = [
            'accounts.view', 'accounts.ban', 'account.view', 'account.edit', 'account.wallet.edit',
            'complaints.view',
            'holders.view', 'holders.create', 'holders.edit',
            'dependants.view', 'dependants.create', 'dependants.edit',
            'withdrawals.view',
            'expenses.view', 'expenses.create', 'expenses.edit',
            'payouts.view', 'payouts.create', 'payouts.decline',
            'payees.view', 'payees.create', 'payees.edit',
            'company-withdrawals.view', 'company-withdrawals.create',
            'wallet-transactions.view',
            'income-distributions.view',
            'deposits.view',
            'bet-shops.view',
            'game-results.view', 'jackpot-results.view', 'tournament-results.view',
            'reports.view',
        ];

        $agentPermissions = [
            'accounts.view',
            'expenses.view', 'expenses.create',
            'game-results.view',
            'reports.view',
        ];

        $roles = [
            'super-admin' => $allPermissions,
            'admin' => $allPermissions,
            'manager' => Permission::whereIn('name', $managerPermissions)->get(),
            'agent' => Permission::whereIn('name', $agentPermissions)->get(),
        ];

        foreach ($roles as $roleName => $perms) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }

        $this->command?->info('Roles & Permissions seeded successfully.');
    }
}
