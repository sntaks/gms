<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use App\Support\Format;
use App\Support\StatsFormat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * `/stats/players`: registrations, first-deposit conversion within 1, 7 and 30 days of signing up
 * ("not yet" while no sign-up is old enough), and 30-day churn.
 */
class StatsPlayersOverviewWidget extends StatsOverviewWidget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 5];

    protected function statsReportKey(): string
    {
        return 'players';
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $stats = $this->stats();

        if ($this->statsProblem !== null) {
            return [Stat::make('Statistics unavailable', '—')->description($this->statsProblem)->color('warning')];
        }

        $conversion = (array) ($stats['first_deposit_conversion'] ?? []);
        $churn = (array) ($stats['churn'] ?? []);
        $cards = [
            Stat::make('Registrations', StatsFormat::count($stats['registrations']['total'] ?? null))
                ->description('Signed up in the period'),
        ];

        foreach ($conversion as $key => $window) {
            if (! is_array($window) || preg_match('/^within_(\d+)_days$/', (string) $key, $match) !== 1) {
                continue;
            }

            $eligible = (int) ($window['eligible'] ?? 0);
            $label = 'First deposit within '.$match[1].' '.($match[1] === '1' ? 'day' : 'days');

            $cards[] = $eligible === 0
                ? Stat::make($label, 'Not yet')->description('No sign-up is old enough yet')->color('gray')
                : Stat::make($label, StatsFormat::count($window['converted'] ?? 0).' / '.StatsFormat::count($eligible))
                    ->description(StatsFormat::percent($window['rate'] ?? null).' converted')
                    ->color('success');
        }

        $cards[] = Stat::make('Churn (30 days)', StatsFormat::percent($churn['rate'] ?? null))
            ->description(StatsFormat::count($churn['churned'] ?? null).' of '.StatsFormat::count($churn['previous_active'] ?? null)
                .' players active '.Format::date($churn['previous']['from'] ?? null).' – '.Format::date($churn['previous']['to'] ?? null).' did not play since')
            ->color('danger');

        return $cards;
    }
}
