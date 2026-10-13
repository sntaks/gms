<?php

return [
    /*
     * Daily payout cap (KES). A payout that exceeds it on its own, or pushes today's
     * running total past it, must be approved by someone other than its maker.
     */
    'daily_cap' => (float) env('PAYOUT_DAILY_CAP', 1000),
];
