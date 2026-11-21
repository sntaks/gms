<?php

namespace App\Concerns;

use App\Services\GameApiService;
use App\Support\StatsFilters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

/**
 * The filter bar, "Definitions" and "Export CSV" of a page built on one KadiApi statistics answer.
 * The page names its answer ({@see statsReport()}) and, when the API offers one, its CSV
 * ({@see statsExport()}); the filters shown are the ones that answer takes ({@see StatsFilters::REPORTS}).
 * Filters live in the URL and the session; widgets get them as `pageFilters`.
 */
trait HasStatsFilters
{
    use HasFiltersForm {
        mountHasFilters as mountFiltersFromQueryOrSession;
    }

    /**
     * The `/stats/{report}` answer the page shows, e.g. `dashboard` or `players/top`.
     */
    abstract public static function statsReport(): string;

    /**
     * The `/stats/export/{report}` CSV with the same answer, if KadiApi offers one.
     */
    abstract public static function statsExport(): ?string;

    /**
     * A shared link may carry only some filters; the missing ones take their defaults (test players
     * left out) instead of an empty form state.
     */
    public function mountHasFilters(): void
    {
        if (filled($this->filters)) {
            $this->filters = [...StatsFilters::defaults(), ...$this->filters];
        }

        $this->mountFiltersFromQueryOrSession();
    }

    public function filtersForm(Schema $schema): Schema
    {
        $takes = StatsFilters::REPORTS[static::statsReport()];

        return $schema->components(array_values(array_filter([
            $takes['period'] ? Select::make('period')
                ->label('Period')
                ->options(StatsFilters::PERIODS)
                ->default('today')
                ->selectablePlaceholder(false)
                ->live() : null,
            $takes['period'] ? DatePicker::make('from')
                ->label('From')
                ->native(false)
                ->maxDate(today())
                ->visible(fn (Get $get): bool => $get('period') === 'custom') : null,
            $takes['period'] ? DatePicker::make('to')
                ->label('Until')
                ->native(false)
                ->maxDate(today())
                ->afterOrEqual('from')
                ->visible(fn (Get $get): bool => $get('period') === 'custom') : null,
            $takes['compare'] ? Select::make('compare')
                ->label('Compared with')
                ->options(StatsFilters::COMPARES)
                ->default('previous_period')
                ->selectablePlaceholder(false) : null,
            $takes['product'] ? Select::make('game')
                ->label('Game')
                ->placeholder('All games')
                ->options(fn (): array => StatsFilters::gameOptions())
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('product', null)) : null,
            $takes['product'] ? Select::make('product')
                ->label('Product')
                ->placeholder('All products')
                ->options(fn (Get $get): array => StatsFilters::productOptions($get('game') ?: null)) : null,
            $takes['ranking'] ? Select::make('sort')
                ->label('Rank by')
                ->options(StatsFilters::SORTS)
                ->default('turnover')
                ->selectablePlaceholder(false) : null,
            $takes['ranking'] ? Select::make('limit')
                ->label('Show')
                ->options(collect(StatsFilters::LIMITS)->mapWithKeys(fn (int $limit): array => [$limit => "Top {$limit}"])->all())
                ->default(20)
                ->selectablePlaceholder(false) : null,
            Toggle::make('exclude_test')
                ->label('Leave out test players')
                ->default(true)
                ->inline(false),
        ])));
    }

    /**
     * The KadiApi query for this page's filters.
     *
     * @return array<string, mixed>
     */
    public function statsQuery(): array
    {
        return StatsFilters::queryFor(static::statsReport(), $this->filters);
    }

    /**
     * @return array<int, Action>
     */
    protected function statsHeaderActions(): array
    {
        return array_values(array_filter([
            Action::make('definitions')
                ->label('Definitions')
                ->icon('heroicon-o-information-circle')
                ->color('gray')
                ->modalHeading('How these figures are defined')
                ->modalWidth(Width::TwoExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.pages.partials.stats-notes', ['notes' => $this->statsNotes()])),
            static::statsExport() !== null ? Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => route('stats.export', ['report' => static::statsExport(), ...$this->exportQuery()])) : null,
        ]));
    }

    /**
     * The export takes the page's own query; the comparison has no CSV of its own.
     *
     * @return array<string, mixed>
     */
    protected function exportQuery(): array
    {
        return array_diff_key($this->statsQuery(), ['compare' => true]);
    }

    /**
     * @return list<string>
     */
    protected function statsNotes(): array
    {
        try {
            $notes = app(GameApiService::class)->statsReport(static::statsReport(), $this->statsQuery())['notes'] ?? [];
        } catch (\Throwable) {
            $notes = [];
        }

        return array_values(array_filter((array) $notes, 'is_string'));
    }
}
