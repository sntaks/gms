<?php

namespace App\Models\Site;

use Database\Factories\Site\SbCompetitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A league or tournament fed by the Odds API (`odds_api_key`).
 */
class SbCompetition extends SiteModel
{
    /** @use HasFactory<SbCompetitionFactory> */
    use HasFactory;

    protected $table = 'sb_competitions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active_in_feed' => 'boolean',
            'is_synced' => 'boolean',
            'auto_settle' => 'boolean',
            'sort_order' => 'integer',
            'scores_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SbSport, $this>
     */
    public function sport(): BelongsTo
    {
        return $this->belongsTo(SbSport::class, 'sport_id');
    }

    /**
     * @return HasMany<SbFixture, $this>
     */
    public function fixtures(): HasMany
    {
        return $this->hasMany(SbFixture::class, 'competition_id');
    }
}
