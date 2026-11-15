<?php

namespace App\Filament\Pages\Widgets;

use App\Exceptions\SiteBackofficeException;
use App\Filament\Pages\SportsbookFixturesPage;
use App\Services\SiteBackofficeClient;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * The Kadi site's Odds API budget this month (`GET odds-api/credits`). Header
 * widget for {@see SportsbookFixturesPage}; lives outside the auto-discovered
 * widgets directory so it never lands on the dashboard.
 */
class OddsApiCreditsWidget extends BaseWidget
{
    public const CACHE_KEY = 'sportsbook:odds-api-credits';

    public const CACHE_SECONDS = 300;

    public const PURPOSES = [
        'pre_match' => 'Pre-match',
        'per_event' => 'Match extras',
        'live' => 'Live',
        'scores' => 'Scores',
        'recording' => 'Recording',
    ];

    protected ?string $heading = 'Odds API credits this month';

    /**
     * @return array<string, mixed>|null
     */
    public static function credits(): ?array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): ?array {
            try {
                return app(SiteBackofficeClient::class)->get('odds-api/credits')->data;
            } catch (SiteBackofficeException) {
                return null;
            }
        });
    }

    protected function getStats(): array
    {
        $credits = static::credits();

        if ($credits === null) {
            return [Stat::make('Odds API credits', '—')->description('The Kadi site back office could not be reached.')->color('gray')];
        }

        $cap = (int) ($credits['monthly_cap'] ?? 0);
        $remaining = (int) ($credits['remaining'] ?? 0);
        $reserve = (int) ($credits['reserve'] ?? 0);

        $stats = [
            Stat::make('Remaining', number_format($remaining))
                ->description('of '.number_format($cap).' this month, '.number_format($reserve).' kept in reserve')
                ->color($remaining <= $reserve ? 'danger' : 'success'),
            Stat::make('Spent', number_format((int) ($credits['spent_this_month'] ?? 0))),
        ];

        foreach (self::PURPOSES as $key => $label) {
            $purpose = $credits['purposes'][$key] ?? null;

            if (! is_array($purpose)) {
                continue;
            }

            $spent = (int) ($purpose['spent'] ?? 0);
            $allowance = $purpose['allowance'] ?? null;

            $stats[] = Stat::make($label, number_format($spent))
                ->description($allowance === null ? 'only the monthly cap applies' : 'of '.number_format((int) $allowance).' allowed')
                ->color($allowance !== null && $spent >= (int) $allowance ? 'warning' : 'gray');
        }

        return $stats;
    }
}
