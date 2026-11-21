<?php

namespace App\Filament\Widgets\Stats\Concerns;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\StatsFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\Log;

/**
 * Gives a dashboard widget the one `/stats/dashboard` answer for the page's filters. Every widget asks
 * with the same query, so {@see GameApiService::statsReport()} serves them from one call. A failed call
 * never throws: the widget shows {@see $statsProblem} instead.
 */
trait ReadsDashboardStats
{
    use InteractsWithPageFilters;

    public ?string $statsProblem = null;

    /**
     * The answer for this request; not kept between Livewire requests, so new filters refetch.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $dashboardAnswer = null;

    /**
     * Company figures: admins and the finance role, like the finance reports.
     */
    public static function canView(): bool
    {
        return auth()->user()?->canViewFinanceReports() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function dashboard(): array
    {
        return $this->dashboardAnswer ??= $this->fetchDashboard();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDashboard(): array
    {
        try {
            $this->statsProblem = null;

            return app(GameApiService::class)->statsReport('dashboard', StatsFilters::query($this->pageFilters));
        } catch (GameApiException $e) {
            Log::warning('Statistics dashboard failed', ['status' => $e->statusCode, 'error' => $e->getMessage()]);
            $this->statsProblem = self::problemMessage($e);

            return [];
        } catch (\Throwable $e) {
            Log::warning('Statistics dashboard failed', ['error' => $e->getMessage()]);
            $this->statsProblem = 'The statistics could not be loaded. Refresh the page to try again.';

            return [];
        }
    }

    public static function problemMessage(GameApiException $e): string
    {
        if ($e->statusCode === 422) {
            $first = collect($e->errors)->flatten()->first();

            return 'The filters were refused: '.(is_string($first) && $first !== '' ? $first : ($e->apiMessage !== '' ? $e->apiMessage : 'check the game and product.'));
        }

        if ($e->statusCode === 429) {
            return 'The statistics limit was reached. Try again in a minute.';
        }

        return 'The statistics could not be loaded. Refresh the page to try again.';
    }
}
