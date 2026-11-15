<?php

namespace Database\Factories\Site;

use App\Models\Site\SbMarket;
use App\Models\Site\SbSelection;

/**
 * @extends SiteFactory<SbSelection>
 */
class SbSelectionFactory extends SiteFactory
{
    protected $model = SbSelection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'market_id' => SbMarket::factory(),
            'outcome' => 'home',
            'name' => 'Home',
            'feed_price' => '2.10',
            'override_price' => null,
        ];
    }
}
