<?php

namespace App\Models\Site;

use Database\Factories\Site\SbBetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sports bet: a Kadi API wager (`wager_reference`). Amounts are cents. `product` is `sportsbook_live` for a bet with a live leg, else null (`sportsbook`).
 */
class SbBet extends SiteModel
{
    /** @use HasFactory<SbBetFactory> */
    use HasFactory;

    protected $table = 'sb_bets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stake' => 'integer',
            'potential_payout' => 'integer',
            'payout' => 'integer',
            'bonus' => 'integer',
            'tax' => 'integer',
            'total_odds' => 'decimal:2',
            'bonus_percent' => 'integer',
            'settlement_version' => 'integer',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
            'accept_after' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SiteUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class, 'user_id');
    }

    /**
     * @return BelongsTo<SbFreeBet, $this>
     */
    public function freeBet(): BelongsTo
    {
        return $this->belongsTo(SbFreeBet::class, 'free_bet_id');
    }

    /**
     * @return HasMany<SbBetLeg, $this>
     */
    public function legs(): HasMany
    {
        return $this->hasMany(SbBetLeg::class, 'bet_id');
    }

    /**
     * The Kadi API product the wager was placed on.
     */
    public function kadiProduct(): string
    {
        return $this->product ?? 'sportsbook';
    }
}
