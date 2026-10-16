<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * Shared display formatting for GameApi-backed Filament pages. Every page must
 * route money, dates, and phone numbers through these helpers so formatting
 * stays identical panel-wide.
 *
 * Badge colour vocabulary — apply consistently on every page:
 *   success — active, settled, won, completed, verified
 *   warning — pending, processing, in-progress, top-tier highlight
 *   danger  — failed, suspended, hidden, rejected
 *   info    — neutral classification tags (type / level / referral code)
 *   gray    — archived, inactive, unknown
 */
class Format
{
    /**
     * Format a monetary amount as `KES 1,234.00`.
     */
    public static function money(int|float|string|null $amount): string
    {
        return 'KES '.number_format((float) ($amount ?? 0), 2);
    }

    /**
     * Format an API date value as `05 May 2026`, or a dash when empty/invalid.
     */
    public static function date(int|string|null $value): string
    {
        return self::carbon($value)?->format('d M Y') ?? '—';
    }

    /**
     * Format an API datetime value as `05 May 2026, 14:30`, or a dash.
     */
    public static function dateTime(int|string|null $value): string
    {
        return self::carbon($value)?->format('d M Y, H:i') ?? '—';
    }

    /**
     * A payer number as KadiApi sends it: M-Pesa replaces some with a SHA-256
     * hash, which is shown as "Hidden by M-Pesa". Anything else is already masked.
     */
    public static function payerPhone(?string $msisdn): string
    {
        if (blank($msisdn)) {
            return '—';
        }

        return self::isHashedPhone($msisdn) ? 'Hidden by M-Pesa' : $msisdn;
    }

    public static function isHashedPhone(?string $msisdn): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/i', (string) $msisdn);
    }

    /**
     * Mask all but the last four digits of a phone number.
     */
    public static function maskedPhone(int|string|null $phone): string
    {
        $phone = (string) $phone;

        return $phone === '' ? '—' : '****'.substr($phone, -4);
    }

    /**
     * A player number for display, `1234 5678`. KadiApi sends the 8 bare digits; anything
     * else is shown as sent, or a dash when empty.
     */
    public static function playerNo(int|string|null $playerNo): string
    {
        $digits = preg_replace('/\D/', '', (string) $playerNo);

        if (strlen($digits) === 8) {
            return substr($digits, 0, 4).' '.substr($digits, 4);
        }

        return blank($playerNo) ? '—' : (string) $playerNo;
    }

    /**
     * Parse an API date into the app (Nairobi) timezone, or null when empty/invalid.
     * M-Pesa's raw `YmdHis` TransTime is Nairobi local time.
     */
    public static function toCarbon(int|string|null $value): ?Carbon
    {
        return self::carbon($value);
    }

    protected static function carbon(int|string|null $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        try {
            if (preg_match('/^\d{14}$/', (string) $value)) {
                return Carbon::createFromFormat('YmdHis', (string) $value, config('app.timezone'));
            }

            // KadiApi may send UTC or +03:00; always show Nairobi (the app timezone).
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Format a number with suffixes (e.g. 1k, 1.2M).
     */
    public static function formatNumber(int $number): string
    {
        $sign = $number < 0 ? '-' : '';
        $magnitude = abs($number);

        if ($magnitude < 1000) {
            return (string) Number::format($number, 0);
        }

        if ($magnitude < 1000000) {
            return $sign.Number::format($magnitude / 1000, 2).'k';
        }

        return $sign.Number::format($magnitude / 1000000, 2).'M';
    }

    /**
     * Simplified figure for dashboard widgets: {@see formatNumber()} on the whole
     * number, or a dash when the value is null, empty or an integer zero.
     */
    public static function compact(int|float|string|null $value): string
    {
        return $value !== null && $value !== '' && $value !== 0 ? self::formatNumber((int) $value) : '—';
    }

    /**
     * {@see compact()} prefixed with the currency, e.g. `KES 1.85M`.
     */
    public static function compactMoney(int|float|string|null $value): string
    {
        return 'KES '.self::compact($value);
    }
}
