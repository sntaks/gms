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
     * Maker-checker: only a super-admin who did not create the payout may approve it.
     * Payouts with an unknown maker are refused.
     */
    public function approve(User $user, Payout $payout): bool
    {
        return $this->isIndependentChecker($user, $payout);
    }

    /**
     * Retrying re-releases a failed payout, so it follows the same maker-checker rule as approval.
     */
    public function retry(User $user, Payout $payout): bool
    {
        return $this->isIndependentChecker($user, $payout);
    }

    private function isIndependentChecker(User $user, Payout $payout): bool
    {
        return $user->hasRole('super-admin')
            && $user->hasPermissionTo('payouts.approve')
            && $payout->created_by !== null
            && $payout->created_by !== $user->id;
    }

    public function decline(User $user, Payout $payout): bool
    {
        return $user->hasPermissionTo('payouts.decline');
    }
}
