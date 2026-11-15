<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A free bet. `status` is `available`, `used` or `void`; amounts are cents.
 */
class SbFreeBet extends SiteModel
{
    protected $table = 'sb_free_bets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'min_odds' => 'decimal:2',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SiteUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class, 'user_id');
    }
}
