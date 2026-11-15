<?php

namespace Database\Factories\Site;

use App\Models\Site\SbFixture;
use App\Models\Site\SbFixtureResult;

/**
 * @extends SiteFactory<SbFixtureResult>
 */
class SbFixtureResultFactory extends SiteFactory
{
    protected $model = SbFixtureResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fixture_id' => SbFixture::factory(),
            'home_score' => 2,
            'away_score' => 1,
            'is_void' => false,
            'source' => 'feed',
        ];
    }
}
