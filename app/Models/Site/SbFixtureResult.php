<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded result. `source` is `feed` or `admin`; `recorded_by_staff` names the GMS user.
 */
class SbFixtureResult extends SiteModel
{
    protected $table = 'sb_fixture_results';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'home_score' => 'integer',
            'away_score' => 'integer',
            'half_time_home_score' => 'integer',
            'half_time_away_score' => 'integer',
            'is_void' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SbFixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(SbFixture::class, 'fixture_id');
    }
}
