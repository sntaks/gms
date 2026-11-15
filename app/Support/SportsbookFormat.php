<?php

namespace App\Support;

use App\Models\Site\SbFixture;
use App\Models\Site\SbMarket;

/**
 * Labels and colours for the Kadi site's sportsbook, as staff should read them
 * (kadi docs/backoffice-api.md, sportsbook-sports.md, sportsbook-in-play.md).
 */
final class SportsbookFormat
{
    /**
     * `sb_markets.suspension_reason` values.
     *
     * @var array<string, string>
     */
    public const SUSPENSION_REASONS = [
        'manual' => 'Suspended by staff',
        'insufficient-bookmakers' => 'Too few bookmakers',
        'kicked-off' => 'Kicked off',
        'stale-odds' => 'Stale odds',
        'score-changed' => 'Score just changed',
        'live-feed-stale' => 'Live feed stale',
        'late-phase' => 'Late in the match',
        'line-decided' => 'Line decided by the score',
        'in-play-off' => 'In-play is off',
    ];

    /**
     * Fixture states shown to staff, derived from the site's columns.
     *
     * @var array<string, string>
     */
    public const FIXTURE_STATES = [
        'upcoming' => 'Upcoming',
        'live' => 'Live',
        'awaiting_result' => 'Awaiting result',
        'resulted' => 'Resulted',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Hours after kick-off by which a match without a feed result is treated as over and needing a result.
     */
    public const MATCH_HOURS = 4;

    public static function fixtureState(SbFixture $fixture): string
    {
        return match (true) {
            $fixture->status === 'cancelled' => 'cancelled',
            $fixture->result !== null => 'resulted',
            $fixture->kicks_off_at->isFuture() => 'upcoming',
            $fixture->feed_completed_at === null && $fixture->kicks_off_at->gt(now()->subHours(self::MATCH_HOURS)) => 'live',
            default => 'awaiting_result',
        };
    }

    public static function fixtureStateColor(string $state): string
    {
        return match ($state) {
            'live' => 'danger',
            'awaiting_result' => 'warning',
            'resulted' => 'success',
            'upcoming' => 'info',
            default => 'gray',
        };
    }

    /**
     * e.g. "1X2", "Winner", "Total 2.5", "Handicap -1.5", "Arsenal goals 1.5".
     */
    public static function marketLabel(SbMarket $market): string
    {
        $line = $market->line === null ? '' : ' '.self::line((string) $market->line);
        $fixture = $market->fixture;

        return match ($market->type) {
            // A two-way h2h (basketball, ice hockey…) has no draw and settles including overtime.
            'h2h' => $market->selections->contains('outcome', 'draw') ? '1X2' : 'Winner',
            'totals' => 'Total'.$line,
            'spreads' => 'Handicap'.($market->line === null ? '' : ' '.self::signedLine((string) $market->line)),
            'home_team_totals' => ($fixture?->home_team ?? 'Home').' total'.$line,
            'away_team_totals' => ($fixture?->away_team ?? 'Away').' total'.$line,
            'double_chance' => 'Double chance',
            'draw_no_bet' => 'Draw no bet',
            'btts' => 'Both teams to score',
            'correct_score' => 'Correct score',
            'ht_ft' => 'Half time / full time',
            default => ucfirst(str_replace('_', ' ', (string) $market->type)).$line,
        };
    }

    public static function suspensionReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return self::SUSPENSION_REASONS[$reason] ?? ucfirst(str_replace('-', ' ', $reason));
    }

    /**
     * A handicap line as the home side's: "-1.5", "+0.5", "0".
     */
    public static function signedLine(string $line): string
    {
        $value = (float) $line;

        return ($value > 0 ? '+' : '').self::line($line);
    }

    public static function line(string $line): string
    {
        return rtrim(rtrim(number_format((float) $line, 2, '.', ''), '0'), '.');
    }

    /**
     * Site amounts are integer cents.
     */
    public static function cents(int|string|null $cents): string
    {
        return Format::money(((int) ($cents ?? 0)) / 100);
    }
}
