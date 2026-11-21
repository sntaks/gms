<?php

namespace App\Filament\Widgets\Stats;

use App\Filament\Widgets\Stats\Concerns\ReadsStats;
use Filament\Widgets\Widget;

/**
 * `/stats/games` `hourly_heatmap`: weekdays down, Nairobi hours across, shaded by stakes placed or by
 * the average number of players per day.
 */
class StatsHeatmapWidget extends Widget
{
    use ReadsStats;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.stats.heatmap';

    /**
     * @var array<string, string>
     */
    public const METRICS = ['stakes' => 'Stakes placed', 'avg_players' => 'Average players'];

    /**
     * @var array<string, string>
     */
    public const WEEKDAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    public string $metric = 'stakes';

    protected function statsReportKey(): string
    {
        return 'games';
    }

    public function showMetric(string $metric): void
    {
        $this->metric = array_key_exists($metric, self::METRICS) ? $metric : 'stakes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $cells = collect((array) ($this->stats()['hourly_heatmap'] ?? []))
            ->filter(fn ($cell): bool => is_array($cell) && isset($cell['weekday'], $cell['hour']))
            ->mapWithKeys(fn (array $cell): array => [$cell['weekday'].':'.(int) $cell['hour'] => (float) ($cell[$this->metric] ?? 0)]);

        return [
            'cells' => $cells->all(),
            'max' => max((float) $cells->max(), 0.0),
            'problem' => $this->statsProblem,
        ];
    }
}
