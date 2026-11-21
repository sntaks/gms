<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsDashboardStats;
use Filament\Widgets\Widget;

/**
 * When the figures were built (`generated_at`), the API's `warnings` (days still waiting for
 * `php artisan stats:rebuild` on KadiApi). The definitions in `notes` open from the page's
 * Definitions button.
 */
class StatsFooterWidget extends Widget
{
    use ReadsDashboardStats;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.stats.footer';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $dashboard = $this->dashboard();

        return [
            'generatedAt' => $dashboard['generated_at'] ?? null,
            'range' => (array) ($dashboard['range'] ?? []),
            'compare' => (array) ($dashboard['compare'] ?? []),
            'warnings' => array_values(array_filter((array) ($dashboard['warnings'] ?? []), 'is_string')),
            'problem' => $this->statsProblem,
        ];
    }
}
