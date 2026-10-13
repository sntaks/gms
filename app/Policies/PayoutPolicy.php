<?php

namespace App\Policies;

use App\Models\Payout;
use App\Models\User;

class PayoutPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('payouts.view');
    }

    public function view(User $user, Payout $payout): bool
    {
        return $user->hasPermissionTo('payouts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('payouts.create');
    }

    public function update(User $user, Payout $payout): bool
    {
        return false;
    }

    public function delete(User $user, Payout $payout): bool
    {
        return $user->hasPermissionTo('payouts.view');
    }

    /**
     * Only super-admins may approve. Payouts within the daily cap may be self-approved by their maker;
     * payouts over the cap need a super-admin who did not create them. Payouts with an unknown maker are refused.
     */
    public function approve(User $user, Payout $payout): bool
    {
        return $this->canRelease($user, $payout);
    }

    /**
     * Retrying re-releases a failed payout, so it follows the same rule as approval.
     */
    public function retry(User $user, Payout $payout): bool
    {
        return $this->canRelease($user, $payout);
    }

    private function canRelease(User $user, Payout $payout): bool
    {
        if (! $user->hasRole('super-admin') || ! $user->hasPermissionTo('payouts.approve') || $payout->created_by === null) {
            return false;
        }

        return ! $payout->requires_independent_approval || $payout->created_by !== $user->id;
    }

    public function decline(User $user, Payout $payout): bool
    {
        return $user->hasPermissionTo('payouts.decline');
    }
}
