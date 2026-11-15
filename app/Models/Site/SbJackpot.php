<?php

namespace App\Models\Site;

use Database\Factories\Site\SbJackpotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sportsbook jackpot. `status` is `draft`, `open`, `settled` or `cancelled`; amounts are cents.
 */
class SbJackpot extends SiteModel
{
    /** @use HasFactory<SbJackpotFactory> */
    use HasFactory;

    protected $table = 'sb_jackpots';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_price' => 'integer',
            'prize_tiers' => 'array',
            'void_limit' => 'integer',
            'settlement_version' => 'integer',
            'published_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SbJackpotFixture, $this>
     */
    public function fixtures(): HasMany
    {
        return $this->hasMany(SbJackpotFixture::class, 'jackpot_id');
    }

    /**
     * @return HasMany<SbJackpotEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SbJackpotEntry::class, 'jackpot_id');
    }
}
