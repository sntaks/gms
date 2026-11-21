<?php

namespace App\Filament\Pages\Statistics;

use App\Concerns\HasStatsFilters;
use App\Filament\Clusters\Statistics\StatisticsCluster;
use App\Filament\Widgets\Stats\StatsFooterWidget;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\WidgetConfiguration;

/**
 * A page built on one KadiApi statistics answer: the filter bar, its widgets in a responsive grid, and
 * a footer with the answer's warnings and build time. Read only: nothing here moves money.
 */
abstract class StatsPage extends Page
{
    use HasStatsFilters;

    protected static ?string $cluster = StatisticsCluster::class;

    /**
     * Company figures: admins and the finance role, like the finance reports.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->canViewFinanceReports() ?? false;
    }

    /**
     * The page's widgets in grid order (two columns from `lg`).
     *
     * @return array<class-string|WidgetConfiguration>
     */
    abstract protected function statsWidgets(): array;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filters')
                ->compact()
                ->collapsible()
                ->schema([EmbeddedSchema::make('filtersForm')]),
            Grid::make(['default' => 1, 'lg' => 2])
                ->schema(fn (): array => $this->statsWidgetComponents()),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function statsWidgetComponents(): array
    {
        return $this->getWidgetsSchemaComponents([
            ...$this->statsWidgets(),
            StatsFooterWidget::make(['report' => static::statsReport()]),
        ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return $this->statsHeaderActions();
    }
}
