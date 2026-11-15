<?php

namespace Database\Factories\Site;

use App\Models\Site\SbFixture;
use App\Models\Site\SbMarket;

/**
 * @extends SiteFactory<SbMarket>
 */
class SbMarketFactory extends SiteFactory
{
    protected $model = SbMarket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fixture_id' => SbFixture::factory(),
            'type' => 'h2h',
            'line' => null,
            'key' => 'h2h',
            'source' => 'feed',
            'status' => 'open',
            'suspension_reason' => null,
            'bookmaker_count' => 5,
            'priced_at' => now(),
        ];
    }
}
