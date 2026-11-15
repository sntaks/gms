<?php

namespace Database\Factories\Site;

use App\Models\Site\SbSport;

/**
 * @extends SiteFactory<SbSport>
 */
class SbSportFactory extends SiteFactory
{
    protected $model = SbSport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Football',
            'slug' => fake()->unique()->slug(1),
            'sort_order' => 100,
        ];
    }
}
