<?php

namespace App\Filament\Widgets\Stats\Concerns;

use App\Filament\Pages\Statistics\StatsLiabilityPage;

/**
 * A liability widget: `/stats/liability` (open exposure now), refreshed every minute, for the
 * roles that may open the Liability page.
 */
trait ReadsLiability
{
    use ReadsStats;

    public static function canView(): bool
    {
        return StatsLiabilityPage::canAccess();
    }

    protected function statsReportKey(): string
    {
        return 'liability';
    }

    /**
     * Shorter than the page's one-minute refresh, so each poll shows a fresh answer.
     */
    protected function statsCacheSeconds(): int
    {
        return 30;
    }
}
