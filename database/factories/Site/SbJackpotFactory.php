<?php

namespace Database\Factories\Site;

use App\Models\Site\SbJackpot;

/**
 * @extends SiteFactory<SbJackpot>
 */
class SbJackpotFactory extends SiteFactory
{
    protected $model = SbJackpot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Weekend Mega Jackpot',
            'status' => 'draft',
            'entry_price' => 9900,
            'prize_tiers' => [['correct' => 13, 'pool' => 100000000], ['correct' => 12, 'pool' => 5000000]],
            'void_limit' => 3,
            'settlement_version' => 1,
        ];
    }
}
