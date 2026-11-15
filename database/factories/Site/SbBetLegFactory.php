<?php

namespace Database\Factories\Site;

use App\Models\Site\SbBet;
use App\Models\Site\SbBetLeg;
use App\Models\Site\SbFixture;

/**
 * @extends SiteFactory<SbBetLeg>
 */
class SbBetLegFactory extends SiteFactory
{
    protected $model = SbBetLeg::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bet_id' => SbBet::factory(),
            'fixture_id' => SbFixture::factory(),
            'fixture_name' => 'Home FC v Away United',
            'kicks_off_at' => now()->addDay(),
            'market_type' => 'h2h',
            'market_name' => '1X2',
            'outcome' => 'home',
            'selection_name' => 'Home FC',
            'price' => '2.10',
            'status' => 'open',
            'in_play' => false,
        ];
    }
}
