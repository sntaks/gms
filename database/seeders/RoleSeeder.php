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
            'bet-shops.reverse-loads',

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

            // Sportsbook (Kadi site back office)
            'sportsbook.view',
            'sportsbook.trade',
            'sportsbook.settle',
            'sportsbook.jackpots',

            // Prediction markets (Kadi API)
            'predict.view',
            'predict.manage',
            'predict.settle',

            // Games catalogue (Kadi API)
            'games.view',
            'games.manage',
            'wagers.void',
            'api-keys.manage',

            // Compliance (Kadi API)
            'withdrawal-approvals.view',
            'withdrawal-approvals.decide',
            'rg.view',
            'rg.manage',
            'accounts.status',
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
            'sportsbook.view',
            'predict.view',
            'games.view',
            'withdrawal-approvals.view',
            'rg.view',
        ];

        $agentPermissions = [
            'accounts.view',
            'expenses.view', 'expenses.create',
            'game-results.view',
            'reports.view',
            // Support needs to see whether a player is excluded or limited.
            'rg.view',
        ];

        // Moves money and settles bets through KadiApi and the Kadi site, but does not manage users or roles.
        $financePermissions = [
            'accounts.view', 'account.view',
            'complaints.view',
            'withdrawals.view',
            'expenses.view', 'expenses.create',
            'payouts.view',
            'company-withdrawals.view',
            'wallet-transactions.view',
            'income-distributions.view',
            'api-income-logs.view',
            'deposits.view', 'deposits.assign', 'deposits.refund', 'deposits.match',
            'bet-shops.view', 'bet-shops.float', 'bet-shops.reverse-loads',
            'referral-withdrawals.settle', 'referral-codes.edit',
            'promo-codes.manage',
            'game-results.view', 'jackpot-results.view', 'tournament-results.view',
            'reports.view', 'excise-duty.remit',
            'sportsbook.view', 'sportsbook.settle', 'sportsbook.jackpots',
            'predict.view', 'predict.settle',
            'games.view', 'wagers.void',
            'withdrawal-approvals.view', 'withdrawal-approvals.decide', 'rg.view', 'rg.manage', 'accounts.status',
        ];

        // Runs sportsbook trading on the Kadi site: suspensions, prices, competitions and jackpot drafts.
        $traderPermissions = [
            'sportsbook.view',
            'sportsbook.trade',
        ];

        $roles = [
            'super-admin' => $allPermissions,
            'admin' => $allPermissions,
            'manager' => Permission::whereIn('name', $managerPermissions)->get(),
            'finance' => Permission::whereIn('name', $financePermissions)->get(),
            'trader' => Permission::whereIn('name', $traderPermissions)->get(),
            'agent' => Permission::whereIn('name', $agentPermissions)->get(),
        ];

        foreach ($roles as $roleName => $perms) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }

        $this->command?->info('Roles & Permissions seeded successfully.');
    }
}
