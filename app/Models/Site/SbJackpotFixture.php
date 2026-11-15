<?php

namespace App\Models\Site;

use Database\Factories\Site\SbJackpotFixtureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A match in a jackpot. `outcome` is `1`, `X` or `2` once decided.
 */
class SbJackpotFixture extends SiteModel
{
    /** @use HasFactory<SbJackpotFixtureFactory> */
    use HasFactory;

    protected $table = 'sb_jackpot_fixtures';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_void' => 'boolean',
            'kicks_off_at' => 'datetime',
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
     * @return BelongsTo<SbFixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(SbFixture::class, 'fixture_id');
    }
}
