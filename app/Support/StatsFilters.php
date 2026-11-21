<?php

namespace App\Support;

use App\Services\GameApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The filters every statistics page shares (period, comparison, game, product, test players) and their
 * translation into the KadiApi `/stats/*` query. The GMS only picks parameters here; every figure comes
 * from the API.
 */
class StatsFilters
{
    /**
     * Period presets KadiApi resolves itself (Nairobi days), plus a custom range sent as from/to.
     *
     * @var array<string, string>
     */
    public const PERIODS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => '7 days',
        '30d' => '30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'custom' => 'Custom',
    ];

    /**
     * @var array<string, string>
     */
    public const COMPARES = [
        'previous_period' => 'Previous period',
        'same_period_last_year' => 'Same period last year',
    ];

    /**
     * @return array{period: string, from: ?string, to: ?string, compare: string, game: ?string, product: ?string, exclude_test: bool}
     */
    public static function defaults(): array
    {
        return ['period' => 'today', 'from' => null, 'to' => null, 'compare' => 'previous_period', 'game' => null, 'product' => null, 'exclude_test' => true];
    }

    /**
     * The API query for a set of page filters. Unknown or empty values fall back to the defaults.
     *
     * @param  array<string, mixed>|null  $filters
     * @param  bool  $withCompare  False for answers with no comparison window.
     * @param  bool  $withProduct  False for platform-wide answers (`/stats/payments` refuses a game or product).
     * @return array<string, mixed>
     */
    public static function query(?array $filters, bool $withCompare = true, bool $withProduct = true): array
    {
        $filters = [...self::defaults(), ...array_filter($filters ?? [], fn ($value): bool => $value !== null && $value !== '')];
        $period = array_key_exists((string) $filters['period'], self::PERIODS) ? (string) $filters['period'] : 'today';

        if ($period === 'custom' && (blank($filters['from']) || blank($filters['to']))) {
            $period = 'today';
        }

        $query = $period === 'custom'
            ? ['from' => Carbon::parse($filters['from'])->toDateString(), 'to' => Carbon::parse($filters['to'])->toDateString()]
            : ['period' => $period];

        $query['group_by'] = self::groupBy($period, $query['from'] ?? null, $query['to'] ?? null);
        $query['exclude_test'] = (bool) $filters['exclude_test'];

        if ($withCompare) {
            $query['compare'] = array_key_exists((string) $filters['compare'], self::COMPARES) ? $filters['compare'] : 'previous_period';
        }

        if ($withProduct) {
            $query['game'] = $filters['game'];
            $query['product'] = $filters['product'];
        }

        return array_filter($query, fn ($value): bool => $value !== null);
    }

    /**
     * Daily points up to a month, weekly up to four months, monthly beyond.
     */
    public static function groupBy(string $period, ?string $from = null, ?string $to = null): string
    {
        if ($period === 'this_year') {
            return now()->dayOfYear() > 120 ? 'month' : 'week';
        }

        if ($period !== 'custom' || $from === null || $to === null) {
            return 'day';
        }

        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to));

        return match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };
    }

    /**
     * Games and their products from `GET /games`, cached for ten minutes.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogue(): array
    {
        try {
            return Cache::remember('stats:catalogue', 600, fn (): array => app(GameApiService::class)->listGames());
        } catch (\Throwable $e) {
            Log::warning('Statistics catalogue unavailable', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    public static function gameOptions(): array
    {
        return collect(self::catalogue())
            ->filter(fn ($game): bool => is_array($game) && filled($game['code'] ?? null))
            ->mapWithKeys(fn (array $game): array => [(string) $game['code'] => (string) ($game['name'] ?? $game['code'])])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function productOptions(?string $game = null): array
    {
        return collect(self::catalogue())
            ->filter(fn ($row): bool => is_array($row) && ($game === null || ($row['code'] ?? null) === $game))
            ->flatMap(fn (array $row): array => (array) ($row['products'] ?? []))
            ->filter(fn ($product): bool => is_array($product) && filled($product['code'] ?? null))
            ->mapWithKeys(fn (array $product): array => [(string) $product['code'] => (string) ($product['name'] ?? $product['code'])])
            ->all();
    }

    public static function gameName(?string $code): string
    {
        return self::gameOptions()[$code] ?? (string) $code;
    }

    public static function productName(?string $code): string
    {
        return self::productOptions()[$code] ?? (string) $code;
    }
}
