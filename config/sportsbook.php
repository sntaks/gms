<?php

/*
|--------------------------------------------------------------------------
| Kadi site sportsbook, as GMS shows it
|--------------------------------------------------------------------------
|
| Thresholds GMS uses to judge in-play health. They mirror the Kadi site's own
| settings (kadi config/sportsbook.php `in_play` and app/Support/PlatformHealth),
| so set them to the site's values if the site changes its own.
|
*/

return [

    'in_play' => [
        // The site suspends a live price older than this (kadi SPORTSBOOK_IN_PLAY_STALE_SECONDS).
        'live_stale_seconds' => (int) env('SPORTSBOOK_IN_PLAY_STALE_SECONDS', 90),

        // The site's live_feed health check alerts once the newest live price is this old (3 x its 40 s tick).
        'feed_alert_seconds' => (int) env('SPORTSBOOK_IN_PLAY_FEED_ALERT_SECONDS', 120),

        // How often the In play page refreshes.
        'poll_seconds' => 15,
    ],

];
