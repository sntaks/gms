<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Builder;

/**
 * The Kadi site's activity log. Staff actions from GMS carry
 * `properties.staff` (with `source: gms`) and no causer.
 */
class SiteActivity extends SiteModel
{
    /**
     * Trading: prices, suspensions, competitions.
     */
    public const LOG_ODDS = 'odds';

    /**
     * Results, voids, cancellations, jackpots and op retries.
     */
    public const LOG_SETTLEMENT = 'settlement';

    protected $table = 'activity_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'attribute_changes' => 'array',
        ];
    }

    /**
     * Only the sportsbook logs GMS is granted.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSportsbook(Builder $query): void
    {
        $query->whereIn('log_name', [self::LOG_ODDS, self::LOG_SETTLEMENT]);
    }

    /**
     * The GMS staff label, e.g. "Jane Trader (GMS #17)", when a GMS user made the change.
     */
    public function staffLabel(): ?string
    {
        $staff = $this->properties['staff'] ?? null;

        if (! is_array($staff) || ! isset($staff['name'], $staff['id'])) {
            return null;
        }

        return "{$staff['name']} (GMS #{$staff['id']})";
    }
}
