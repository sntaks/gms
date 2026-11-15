<?php

namespace App\Models\Site;

/**
 * One Odds API call and the credits it spent. `purpose` is `pre_match`, `per_event`, `live`, `scores` or `recording`.
 */
class SbOddsApiCall extends SiteModel
{
    protected $table = 'sb_odds_api_calls';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits_spent' => 'integer',
            'credits_remaining' => 'integer',
            'credits_used' => 'integer',
            'http_status' => 'integer',
            'succeeded' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
