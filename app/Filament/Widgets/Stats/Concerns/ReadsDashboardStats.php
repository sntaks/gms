<?php

namespace App\Filament\Widgets\Stats\Concerns;

/**
 * A dashboard widget: reads the one `/stats/dashboard` answer for the page's filters.
 */
trait ReadsDashboardStats
{
    use ReadsStats;

    /**
     * @return array<string, mixed>
     */
    protected function dashboard(): array
    {
        return $this->stats();
    }
}
