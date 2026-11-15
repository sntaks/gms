<?php

namespace Database\Factories\Site;

use App\Models\Site\SbCompetition;
use App\Models\Site\SbSport;

/**
 * @extends SiteFactory<SbCompetition>
 */
class SbCompetitionFactory extends SiteFactory
{
    protected $model = SbCompetition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sport_id' => SbSport::factory(),
            'odds_api_key' => 'soccer_'.fake()->unique()->word(),
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'is_active_in_feed' => true,
            'is_synced' => true,
            'auto_settle' => true,
            'sort_order' => 100,
        ];
    }
}
