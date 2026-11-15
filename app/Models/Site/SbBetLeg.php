<?php

namespace App\Models\Site;

use Database\Factories\Site\SbBetLegFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pick of a bet. `status` is `open`, `won`, `lost` or `void`.
 */
class SbBetLeg extends SiteModel
{
    /** @use HasFactory<SbBetLegFactory> */
    use HasFactory;

    protected $table = 'sb_bet_legs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'market_line' => 'decimal:2',
            'price' => 'decimal:2',
            'in_play' => 'boolean',
            'kicks_off_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SbBet, $this>
     */
    public function bet(): BelongsTo
    {
        return $this->belongsTo(SbBet::class, 'bet_id');
    }

    /**
     * @return BelongsTo<SbFixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(SbFixture::class, 'fixture_id');
    }

    /**
     * @return BelongsTo<SbMarket, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(SbMarket::class, 'market_id');
    }

    /**
     * @return BelongsTo<SbSelection, $this>
     */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(SbSelection::class, 'selection_id');
    }
}
