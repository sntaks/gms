<?php

namespace Database\Factories\Site;

use App\Models\Site\SiteUser;

/**
 * @extends SiteFactory<SiteUser>
 */
class SiteUserFactory extends SiteFactory
{
    protected $model = SiteUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'linked_id' => fake()->unique()->numberBetween(1000, 999999),
        ];
    }
}
