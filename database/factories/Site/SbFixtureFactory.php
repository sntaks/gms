<?php

namespace Database\Factories\Site;

use App\Models\Site\SbCompetition;
use App\Models\Site\SbFixture;

/**
 * @extends SiteFactory<SbFixture>
 */
class SbFixtureFactory extends SiteFactory
{
    protected $model = SbFixture::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => SbCompetition::factory(),
            'external_id' => fake()->unique()->uuid(),
            'home_team' => fake()->city().' FC',
            'away_team' => fake()->city().' United',
            'kicks_off_at' => now()->addDay(),
            'status' => 'scheduled',
        ];
    }
}
