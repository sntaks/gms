<?php

namespace App\Services;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use Closure;
use Illuminate\Support\Facades\Cache;

class PayoutDailyCap
{
    public function cap(): float
    {
        return (float) config('payouts.daily_cap');
    }

    /**
     * Total of payouts created today (app timezone), excluding declined ones.
     */
    public function usedToday(): float
    {
        return (float) Payout::query()
            ->where('status', '!=', PayoutStatus::Declined)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('amount');
    }

    public function remainingToday(): float
    {
        return max(0, $this->cap() - $this->usedToday());
    }

    /**
     * Whether a new payout of the given amount needs a checker other than its maker.
     */
    public function requiresIndependentApproval(float $amount): bool
    {
        return $amount > $this->cap() || $this->usedToday() + $amount > $this->cap();
    }

    /**
     * Run the callback while holding the cap lock, so concurrent creations cannot both slip under the cap.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withLock(Closure $callback): mixed
    {
        return Cache::lock('payouts:daily-cap', 10)->block(5, $callback);
    }
}
