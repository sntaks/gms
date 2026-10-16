<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Filament\Pages\Widgets\FinanceSummaryWidget;
use App\Services\GameApiService;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Shared base for reports backed by the wallet API's `/finance/*` endpoints.
 *
 * A subclass names its endpoint and maps the payload into summary cards
 * ({@see summaryStats()}) and read-only breakdown tables ({@see blocks()}).
 * Paged drill-downs extend {@see FinanceListReportPage} instead. Access is
 * limited to admins holding `reports.view` because these are company-level
 * financials.
 */
abstract class FinanceReportPage extends BaseReportPage
{
    protected static ?string $cluster = FinanceReportsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::ACTIVITY;

    protected string $view = 'filament.pages.finance-report';

    /**
     * Per-request memo so the report is fetched once even though the widget,
     * infolist, and view all read it.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $cachedReport = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isAdmin() ?? false) && $user->hasPermissionTo('reports.view');
    }

    /**
     * The `/finance/{key}` endpoint this page reads.
     */
    abstract protected function reportKey(): string;

    /**
     * The `/finance/export/{report}` key used by the CSV download, if the API offers one.
     */
    protected function exportKey(): ?string
    {
        return null;
    }

    /**
     * Whether the report is bounded by the period filter (balance sheet, for one, is not).
     */
    protected function usesPeriod(): bool
    {
        return true;
    }

    /**
     * Report-specific query filters merged over the period filters.
     *
     * @return array<string, mixed>
     */
    protected function extraFilters(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, value: string, description?: string, color?: string, icon?: string}>
     */
    protected function summaryStats(array $data): array
    {
        return [];
    }

    /**
     * Read-only breakdown tables rendered below the summary cards. Cell values
     * must already be display-formatted strings.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{title: string, headers: list<string>, rows: list<list<string>>, description?: string}>
     */
    protected function blocks(array $data): array
    {
        return [];
    }

    /**
     * Bars rendered above the breakdown tables, e.g. a budget used against its cap.
     * `value` and `max` are raw amounts; `description` is display-formatted.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, value: float, max: float, description: string}>
     */
    protected function progressBars(array $data): array
    {
        return [];
    }

    /**
     * @return list<array{label: string, description: string, percent: float, color: string}>
     */
    public function getProgressBars(): array
    {
        return collect($this->progressBars($this->getReport()))
            ->filter(fn (array $bar): bool => $bar['max'] > 0)
            ->map(function (array $bar): array {
                $percent = round($bar['value'] / $bar['max'] * 100, 1);

                return [
                    'label' => $bar['label'],
                    'description' => $bar['description'],
                    'percent' => $percent,
                    'color' => match (true) {
                        $percent >= 100 => 'danger',
                        $percent >= 80 => 'warning',
                        default => 'primary',
                    },
                ];
            })
            ->values()
            ->all();
    }

    public function hasItemsTable(): bool
    {
        return false;
    }

    /**
     * A money figure that the API may send as a plain number or as a block with a `total`.
     */
    protected static function amountOf(mixed $value): float
    {
        if (is_array($value)) {
            return (float) ($value['total'] ?? $value['amount'] ?? 0);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [FinanceSummaryWidget::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return ['stats' => $this->summaryStats($this->getReport())];
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        if ($this->cachedReport !== null) {
            return $this->cachedReport;
        }

        try {
            $data = app(GameApiService::class)->financeReport($this->reportKey(), $this->queryFilters());
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Finance report failed', ['report' => $this->reportKey(), 'error' => $e->getMessage()]);
            $this->apiError = true;
            $data = [];
        }

        return $this->cachedReport = $data;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return array_values(array_filter((array) ($this->getReport()['meta']['warnings'] ?? []), 'is_string'));
    }

    /**
     * Query string for the API: the resolved period plus any report filters.
     *
     * @return array<string, mixed>
     */
    protected function queryFilters(): array
    {
        if (! $this->usesPeriod()) {
            return $this->extraFilters();
        }

        [$from, $to] = $this->financeRange();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group_by' => $this->groupBy($from, $to),
            ...$this->extraFilters(),
        ];
    }

    /**
     * Resolve the period filter into a range the API accepts: never in the
     * future and at most 366 days long (`all_time` becomes the trailing year).
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    protected function financeRange(): array
    {
        [$start, $end] = $this->dateRange();

        $to = $end ? Carbon::parse($end)->min(today()) : today();
        $from = $start ? Carbon::parse($start) : $to->copy()->subDays(365);

        if ($from->gt($to)) {
            $from = $to->copy();
        }

        if ($from->diffInDays($to) > 365) {
            $from = $to->copy()->subDays(365);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    protected function groupBy(CarbonInterface $from, CarbonInterface $to): string
    {
        $days = $from->diffInDays($to);

        return match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };
    }

    public function reportInfolist(Schema $schema): Schema
    {
        $blocks = $this->blocks($this->getReport());

        $state = [];
        $sections = [];

        foreach ($blocks as $index => $block) {
            $state["block_{$index}"] = collect($block['rows'])
                ->map(fn (array $row): array => collect($row)->mapWithKeys(fn ($cell, $key): array => ["c{$key}" => $cell])->all())
                ->all();

            $sections[] = Section::make($block['title'])
                ->description($block['description'] ?? null)
                ->collapsible()
                ->schema([
                    RepeatableEntry::make("block_{$index}")
                        ->hiddenLabel()
                        ->placeholder('Nothing recorded for this period.')
                        ->table(array_map(fn (string $header): TableColumn => TableColumn::make($header), $block['headers']))
                        ->schema(array_map(
                            fn (int $key): TextEntry => TextEntry::make("c{$key}"),
                            array_keys($block['headers']),
                        )),
                ]);
        }

        return $schema
            ->constantState(fn (): array => $state)
            ->components($sections);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = $this->usesPeriod() ? parent::getHeaderActions() : [];

        if ($this->exportKey() !== null) {
            $actions[] = Action::make('exportCsv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => route('finance.export', ['report' => $this->exportKey(), ...$this->queryFilters()]));
        }

        return $actions;
    }

    protected function onFilterApplied(): void
    {
        $this->cachedReport = null;
        $this->refresh();
    }
}
