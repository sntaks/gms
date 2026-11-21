<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Widgets\Stats\StatsFooterWidget;
use App\Filament\Widgets\Stats\StatsHeatmapWidget;
use App\Filament\Widgets\Stats\StatsProductSeriesChartWidget;
use App\Services\GameApiService;
use BackedEnum;
use Filament\Schemas\Components\Livewire;

/**
 * `/stats/games`: a turnover, GGR and RTP chart per product (narrowed by the game and product
 * filters) and the weekday-by-hour heatmap.
 */
class StatsGamesPage extends StatsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationLabel = 'Games';

    protected static ?string $title = 'Games and products';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'games';

    public static function statsReport(): string
    {
        return 'games';
    }

    public static function statsExport(): ?string
    {
        return 'stats-games';
    }

    protected function statsWidgets(): array
    {
        return [StatsHeatmapWidget::class];
    }

    /**
     * One chart per product in the answer. Each is keyed by its product so a new filter mounts the
     * right charts instead of reusing the previous product's.
     *
     * @return array<int, mixed>
     */
    protected function statsWidgetComponents(): array
    {
        try {
            $rows = (array) (app(GameApiService::class)->statsReport('games', $this->statsQuery())['by_product'] ?? []);
        } catch (\Throwable) {
            $rows = [];
        }

        $charts = collect($rows)
            ->filter(fn ($row): bool => is_array($row) && filled($row['product'] ?? null))
            ->map(fn (array $row): Livewire => Livewire::make(StatsProductSeriesChartWidget::class, fn (): array => [
                'product' => (string) $row['product'],
                'pageFilters' => $this->filters,
            ])->key('stats-product-'.$row['product'])->liberatedFromContainerGrid())
            ->values()
            ->all();

        return [
            ...$charts,
            ...$this->getWidgetsSchemaComponents([StatsHeatmapWidget::class, StatsFooterWidget::make(['report' => 'games'])]),
        ];
    }
}
