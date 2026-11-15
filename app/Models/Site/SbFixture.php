<?php

namespace App\Models\Site;

use Database\Factories\Site\SbFixtureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A match. `status` is `scheduled` or `cancelled`; the live score sits here, the result in sb_fixture_results.
 */
class SbFixture extends SiteModel
{
    /** @use HasFactory<SbFixtureFactory> */
    use HasFactory;

    protected $table = 'sb_fixtures';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kicks_off_at' => 'datetime',
            'last_seen_in_feed_at' => 'datetime',
            'home_score' => 'integer',
            'away_score' => 'integer',
            'score_updated_at' => 'datetime',
            'score_changed_at' => 'datetime',
            'feed_completed_at' => 'datetime',
            'extras_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SbCompetition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(SbCompetition::class, 'competition_id');
    }

    /**
     * @return HasMany<SbMarket, $this>
     */
    public function markets(): HasMany
    {
        return $this->hasMany(SbMarket::class, 'fixture_id');
    }

    /**
     * @return HasMany<SbFixtureResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(SbFixtureResult::class, 'fixture_id');
    }

    /**
     * The result as recorded last (a new recording corrects the previous one).
     *
     * @return HasOne<SbFixtureResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(SbFixtureResult::class, 'fixture_id')->latestOfMany();
    }
}
