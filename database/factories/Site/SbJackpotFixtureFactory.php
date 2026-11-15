<?php

namespace Database\Factories\Site;

use App\Models\Site\SbFixture;
use App\Models\Site\SbJackpot;
use App\Models\Site\SbJackpotFixture;

/**
 * @extends SiteFactory<SbJackpotFixture>
 */
class SbJackpotFixtureFactory extends SiteFactory
{
    protected $model = SbJackpotFixture::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jackpot_id' => SbJackpot::factory(),
            'fixture_id' => SbFixture::factory(),
            'position' => 1,
            'fixture_name' => 'Arsenal v Chelsea',
            'kicks_off_at' => now()->addDay(),
            'outcome' => null,
            'is_void' => false,
        ];
    }
}
