<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Display rules for KadiApi statistics: KES and percentages with two decimals, "n/a" where the API
 * sends null (Kadi rake products have no payouts or RTP), "–" for a change with nothing to compare,
 * and times in Africa/Nairobi. Nothing here computes a figure.
 */
class StatsFormat
{
    public const TIMEZONE = 'Africa/Nairobi';

    /**
     * Tooltips in the words of the API's `notes`.
     *
     * @var array<string, string>
     */
    public const TOOLTIPS = [
        'bets' => 'Wagers settled won or lost. For Kadi games, tournaments and jackpots: game and competition entries.',
        'voids' => 'Wagers settled void, counted apart from bets.',
        'turnover' => 'Cash stakes settled to the house; free bets are not turnover. For Kadi: stakes put into games and competitions, less refunds.',
        'payouts' => 'Cash winnings paid by the house before winnings tax. Kadi rake products have no payouts.',
        'ggr' => 'Turnover minus payouts for house-banked products; the house cut for Kadi rake products.',
        'hold' => 'GGR as a percentage of turnover.',
        'rtp' => 'Payouts as a percentage of house-banked turnover. Kadi rake products have no RTP.',
        'promotions' => 'Bonuses and free-bet winnings paid by the house on wagers.',
        'net' => 'GGR minus promotions.',
        'avg_stake' => 'Turnover divided by bets.',
        'players' => 'Distinct players with at least one wager or Kadi game or competition entry in the period.',
        'active_players' => 'Distinct players with at least one wager or Kadi game or competition entry in the period.',
        'new_players' => 'Players who registered in the period.',
        'first_depositors' => 'Players who made their first deposit or bet shop load in the period.',
        'deposits' => 'Wallet deposits and bet shop loads received.',
        'withdrawals' => 'Withdrawals paid out.',
        'net_cash' => 'Deposits minus withdrawals paid (cash-flow net cash).',
        'open_wagers' => 'Wagers placed and not yet settled, now.',
        'open_stakes' => 'Stakes on wagers not yet settled, now.',
        'open_potential_payout' => 'What open wagers could pay now: worst side for prediction markets, as reported by the game otherwise.',
    ];

    public static function money(int|float|string|null $amount): string
    {
        return $amount === null || $amount === '' ? 'n/a' : Format::money($amount);
    }

    public static function percent(int|float|string|null $value): string
    {
        return $value === null || $value === '' ? 'n/a' : number_format((float) $value, 2).'%';
    }

    public static function count(int|float|string|null $value): string
    {
        return $value === null || $value === '' ? 'n/a' : number_format((float) $value);
    }

    /**
     * A change in percent against the comparison window: "–" when the API has none (previous was 0).
     *
     * @return array{label: string, color: string, icon: ?string}
     */
    public static function delta(int|float|string|null $deltaPct): array
    {
        if ($deltaPct === null || $deltaPct === '') {
            return ['label' => '–', 'color' => 'gray', 'icon' => null];
        }

        $value = (float) $deltaPct;

        return match (true) {
            round($value, 2) > 0 => ['label' => '+'.number_format($value, 2).'%', 'color' => 'success', 'icon' => 'heroicon-m-arrow-trending-up'],
            round($value, 2) < 0 => ['label' => number_format($value, 2).'%', 'color' => 'danger', 'icon' => 'heroicon-m-arrow-trending-down'],
            default => ['label' => '0.00%', 'color' => 'gray', 'icon' => null],
        };
    }

    public static function time(?string $value): string
    {
        if (blank($value)) {
            return '—';
        }

        try {
            return Carbon::parse($value)->setTimezone(self::TIMEZONE)->format('d M Y, H:i');
        } catch (\Throwable) {
            return '—';
        }
    }

    public static function tooltip(string $figure): ?string
    {
        return self::TOOLTIPS[$figure] ?? null;
    }
}
