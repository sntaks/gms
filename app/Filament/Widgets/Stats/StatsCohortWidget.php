<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use Filament\Widgets\Widget;

/**
 * `/stats/players` `retention_cohorts`: for each registration week, the share of its players active
 * in each of the next eight weeks. Weeks that have not ended yet are empty.
 */
class StatsCohortWidget extends Widget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.stats.cohorts';

    protected function statsReportKey(): string
    {
        return 'players';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'cohorts' => array_values(array_filter((array) ($this->stats()['retention_cohorts'] ?? []), 'is_array')),
            'problem' => $this->statsProblem,
        ];
    }
}
