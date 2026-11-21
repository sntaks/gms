<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsDashboardStats;
use App\Support\StatsFormat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The headline figures of `/stats/dashboard` `totals`, each with its change against `previous`.
 * With a game or product filter the platform-wide cash and sign-up figures are null and their cards
 * are left out rather than shown as 0.
 */
class StatsKpiWidget extends StatsOverviewWidget
{
    use ReadsDashboardStats;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 5];

    /**
     * Card key => [label, kind]. Kinds: money, percent, count.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CARDS = [
        'ggr' => ['GGR', 'money'],
        'net' => ['Net', 'money'],
        'turnover' => ['Turnover', 'money'],
        'hold' => ['Hold %', 'percent'],
        'active_players' => ['Active players', 'count'],
        'new_players' => ['New players', 'count'],
        'first_depositors' => ['First depositors', 'count'],
        'deposits' => ['Deposits', 'money'],
        'withdrawals' => ['Withdrawals', 'money'],
        'net_cash' => ['Net cash', 'money'],
    ];

    /**
     * Cards that are null, and so hidden, when the page is filtered by a game or product.
     *
     * @var list<string>
     */
    public const PLATFORM_ONLY = ['new_players', 'first_depositors', 'deposits', 'withdrawals', 'net_cash'];

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $dashboard = $this->dashboard();

        if ($this->statsProblem !== null) {
            return [Stat::make('Statistics unavailable', '—')->description($this->statsProblem)->color('warning')];
        }

        $totals = (array) ($dashboard['totals'] ?? []);
        $series = collect((array) ($dashboard['series'] ?? []));
        $stats = [];

        foreach (self::CARDS as $key => [$label, $kind]) {
            if (! array_key_exists($key, $totals) || $totals[$key] === null) {
                continue;
            }

            $delta = StatsFormat::delta($totals['delta_pct'][$key] ?? null);
            $previous = $totals['previous'][$key] ?? null;

            $stat = Stat::make($label, self::formatted($totals[$key], $kind))
                ->description($delta['label'].($previous !== null ? ' vs '.self::formatted($previous, $kind) : ''))
                ->descriptionIcon($delta['icon'])
                ->color($delta['color']);

            $points = $series->pluck($key)->filter(fn ($value): bool => is_numeric($value))->map(fn ($value): float => (float) $value)->values()->all();

            if (count($points) > 1) {
                $stat->chart($points);
            }

            if (($tooltip = StatsFormat::tooltip($key)) !== null) {
                $stat->extraAttributes(['title' => $tooltip]);
            }

            $stats[] = $stat;
        }

        return $stats;
    }

    protected static function formatted(mixed $value, string $kind): string
    {
        return match ($kind) {
            'money' => StatsFormat::money($value),
            'percent' => StatsFormat::percent($value),
            default => StatsFormat::count($value),
        };
    }
}
