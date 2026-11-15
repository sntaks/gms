<?php

namespace App\Models\Site;

use Database\Factories\Site\SbJackpotEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A jackpot entry: a Kadi API wager (`wager_reference`). Amounts are cents.
 */
class SbJackpotEntry extends SiteModel
{
    /** @use HasFactory<SbJackpotEntryFactory> */
    use HasFactory;

    protected $table = 'sb_jackpot_entries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stake' => 'integer',
            'correct_count' => 'integer',
            'prize' => 'integer',
            'tax' => 'integer',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SbJackpot, $this>
     */
    public function jackpot(): BelongsTo
    {
        return $this->belongsTo(SbJackpot::class, 'jackpot_id');
    }

    /**
     * @return BelongsTo<SiteUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class, 'user_id');
    }
}
