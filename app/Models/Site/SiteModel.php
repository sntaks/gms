<?php

namespace App\Models\Site;

use App\Exceptions\ReadOnlySiteModelException;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * A row of the Kadi site's database, read through the SELECT-only `kadi_site`
 * connection. GMS never writes these tables: every change goes through the
 * site's signed back-office API (App\Services\SiteBackofficeClient), or the
 * site's outbox and audit trail would be bypassed. Saving or deleting throws,
 * as a second guard behind the database grant.
 *
 * The site stores every datetime in UTC. They are read as UTC here; show them
 * with `->timezone(config('app.timezone'))`.
 */
abstract class SiteModel extends Model
{
    protected $connection = 'kadi_site';

    protected $guarded = [];

    private static bool $writesAllowed = false;

    /**
     * Run a callback that may write site rows. For test factories only: the
     * production connection has no write grant.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function allowingWrites(callable $callback): mixed
    {
        $previous = self::$writesAllowed;
        self::$writesAllowed = true;

        try {
            return $callback();
        } finally {
            self::$writesAllowed = $previous;
        }
    }

    public static function writesAllowed(): bool
    {
        return self::$writesAllowed;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->refuseWrite('save');

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        $this->refuseWrite('delete');

        return parent::delete();
    }

    /**
     * @param  Builder  $query
     */
    public function newEloquentBuilder($query): ReadOnlyBuilder
    {
        return new ReadOnlyBuilder($query);
    }

    /**
     * The site writes UTC wall-clock times, so read them as UTC rather than in the app's timezone.
     *
     * @param  mixed  $value
     */
    protected function asDateTime($value): CarbonInterface
    {
        $date = parent::asDateTime($value);

        return is_string($value) || is_numeric($value) ? $date->shiftTimezone('UTC') : $date;
    }

    /**
     * Store UTC, as the site does (test factories only).
     *
     * @param  mixed  $value
     */
    public function fromDateTime($value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            $value = Carbon::instance($value)->utc();
        }

        return parent::fromDateTime($value);
    }

    private function refuseWrite(string $operation): void
    {
        if (! self::$writesAllowed) {
            throw ReadOnlySiteModelException::for(static::class, $operation);
        }
    }
}
