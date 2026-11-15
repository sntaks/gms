<?php

namespace Database\Factories\Site;

use App\Models\Site\SbJackpot;
use App\Models\Site\SbJackpotEntry;
use App\Models\Site\SiteUser;

/**
 * @extends SiteFactory<SbJackpotEntry>
 */
class SbJackpotEntryFactory extends SiteFactory
{
    protected $model = SbJackpotEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jackpot_id' => SbJackpot::factory(),
            'user_id' => SiteUser::factory(),
            'reference' => strtoupper(fake()->unique()->bothify('J??####')),
            'wager_reference' => fake()->uuid(),
            'status' => 'open',
            'stake' => 9900,
            'placed_at' => now(),
        ];
    }
}
