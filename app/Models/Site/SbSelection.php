<?php

namespace App\Models\Site;

use Database\Factories\Site\SbSelectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An outcome of a market. The price shown to players is `override_price` when set, else `feed_price`.
 */
class SbSelection extends SiteModel
{
    /** @use HasFactory<SbSelectionFactory> */
    use HasFactory;

    protected $table = 'sb_selections';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fair_probability' => 'decimal:6',
            'feed_price' => 'decimal:2',
            'override_price' => 'decimal:2',
            'previous_price' => 'decimal:2',
            'price_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SbMarket, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(SbMarket::class, 'market_id');
    }

    /**
     * The price players get: the staff override, else the feed price.
     */
    public function effectivePrice(): ?string
    {
        return $this->override_price ?? $this->feed_price;
    }
}
