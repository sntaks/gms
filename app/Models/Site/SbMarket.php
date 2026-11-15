<?php

namespace App\Models\Site;

use Database\Factories\Site\SbMarketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A market on a fixture. `status` is `open` or `suspended`; for `spreads`, `line` is the home line.
 */
class SbMarket extends SiteModel
{
    /** @use HasFactory<SbMarketFactory> */
    use HasFactory;

    protected $table = 'sb_markets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'decimal:2',
            'bookmaker_count' => 'integer',
            'priced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SbFixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(SbFixture::class, 'fixture_id');
    }

    /**
     * @return HasMany<SbSelection, $this>
     */
    public function selections(): HasMany
    {
        return $this->hasMany(SbSelection::class, 'market_id');
    }
}
