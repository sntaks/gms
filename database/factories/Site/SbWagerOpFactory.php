<?php

namespace Database\Factories\Site;

use App\Models\Site\SbWagerOp;

/**
 * @extends SiteFactory<SbWagerOp>
 */
class SbWagerOpFactory extends SiteFactory
{
    protected $model = SbWagerOp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product' => 'sportsbook',
            'wager_reference' => fake()->uuid(),
            'subject_type' => 'bet',
            'subject_id' => fake()->numberBetween(1, 100000),
            'operation' => 'settle',
            'version' => 1,
            'payload' => ['outcome' => 'won', 'payout' => 21000, 'bonus' => 0],
            'fingerprint' => hash('sha256', fake()->uuid()),
            'status' => 'pending',
            'attempts' => 0,
        ];
    }
}
