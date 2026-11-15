<?php

namespace App\Models\Site;

use Database\Factories\Site\SbWagerOpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The outbox that settles and reverses wagers on the Kadi API. `status` is `pending`, `sent` or `failed`; `payload` holds amounts only.
 */
class SbWagerOp extends SiteModel
{
    /** @use HasFactory<SbWagerOpFactory> */
    use HasFactory;

    protected $table = 'sb_wager_ops';

    /**
     * @var list<string>
     */
    protected $hidden = ['fingerprint'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'version' => 'integer',
            'attempts' => 'integer',
            'last_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
