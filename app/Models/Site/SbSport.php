<?php

namespace App\Models\Site;

use Database\Factories\Site\SbSportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sport (football, basketball, ice hockey…).
 */
class SbSport extends SiteModel
{
    /** @use HasFactory<SbSportFactory> */
    use HasFactory;

    protected $table = 'sb_sports';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<SbCompetition, $this>
     */
    public function competitions(): HasMany
    {
        return $this->hasMany(SbCompetition::class, 'sport_id');
    }
}
