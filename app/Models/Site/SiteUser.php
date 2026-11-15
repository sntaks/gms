<?php

namespace App\Models\Site;

use Database\Factories\Site\SiteUserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A player on the Kadi site. GMS may read only `id`, `name`, `email` and
 * `linked_id` (column grants), so every query selects just those. `linked_id`
 * is the Kadi API customer id.
 */
class SiteUser extends SiteModel
{
    /** @use HasFactory<SiteUserFactory> */
    use HasFactory;

    /**
     * The columns the read-only MySQL user is granted.
     *
     * @var list<string>
     */
    public const GRANTED_COLUMNS = ['id', 'name', 'email', 'linked_id'];

    protected $table = 'users';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope('granted_columns', function (Builder $query): void {
            $query->select(array_map(fn (string $column): string => 'users.'.$column, self::GRANTED_COLUMNS));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<SbBet, $this>
     */
    public function bets(): HasMany
    {
        return $this->hasMany(SbBet::class, 'user_id');
    }
}
