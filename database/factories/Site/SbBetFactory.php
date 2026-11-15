<?php

namespace Database\Factories\Site;

use App\Models\Site\SbBet;
use App\Models\Site\SiteUser;

/**
 * @extends SiteFactory<SbBet>
 */
class SbBetFactory extends SiteFactory
{
    protected $model = SbBet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => SiteUser::factory(),
            'reference' => strtoupper(fake()->unique()->bothify('??####??')),
            'wager_reference' => fake()->uuid(),
            'type' => 'single',
            'status' => 'open',
            'product' => null,
            'stake' => 10000,
            'total_odds' => '2.10',
            'potential_payout' => 21000,
            'odds_change_policy' => 'higher',
            'settlement_version' => 0,
            'placed_at' => now(),
        ];
    }
}
