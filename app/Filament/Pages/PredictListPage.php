<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesPredictWrites;
use App\Exceptions\GameApiException;
use App\Support\ApiTablePaginator;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Base for the Kadi API prediction-market queues and logs (proposals,
 * corrections, risk events, audit, report): a paged API list with optional
 * status tabs. Viewing needs `predict.view`.
 */
abstract class PredictListPage extends Page implements HasTable
{
    use HandlesPredictWrites;
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = '🔮 Predictions';

    protected string $view = 'filament.pages.predict-list-page';

    #[Url]
    public string $tab = '';

    public bool $apiError = false;

    /**
     * @return array<string, string> Tab key => label; empty for no tabs.
     */
    public static function tabs(): array
    {
        return [];
    }

    /**
     * One page of the list from the Kadi API.
     *
     * @param  array<string, mixed>  $filters  The tab's filters plus page and per_page.
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    abstract protected function fetch(array $filters): array;

    /**
     * The API filters a tab stands for.
     *
     * @return array<string, mixed>
     */
    protected function tabFilters(string $tab): array
    {
        return $tab === '' || $tab === 'all' ? [] : ['status' => $tab];
    }

    abstract protected function configureTable(Table $table): Table;

    public static function canAccess(): bool
    {
        return static::canViewPredict();
    }

    public function mount(): void
    {
        $tabs = static::tabs();

        if ($tabs !== [] && ! array_key_exists($this->tab, $tabs)) {
            $this->tab = (string) array_key_first($tabs);
        }
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, static::tabs())) {
            return;
        }

        $this->tab = $tab;
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $this->configureTable($table
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->load((int) $page, (int) $recordsPerPage),
            ))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Unavailable' : 'Nothing here')
            ->emptyStateDescription(fn (): ?string => $this->apiError ? 'KadiApi could not be reached. Refresh the page to try again.' : null)
            ->striped());
    }

    /**
     * @return array<string, mixed>
     */
    protected function load(int $page, int $perPage): array
    {
        try {
            $data = $this->fetch([...$this->tabFilters($this->tab), 'page' => max(1, $page), 'per_page' => min(100, max(1, $perPage))]);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning(static::class.' list failed', ['tab' => $this->tab, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $key = static::recordKey();
        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row[$key]))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row[$key]])
            ->values()
            ->all();

        return $data;
    }

    protected static function recordKey(): string
    {
        return 'id';
    }

    protected function afterPredictWrite(): void
    {
        $this->flushCachedTableRecords();
    }

    /**
     * A link to a market's page.
     */
    public static function marketUrl(mixed $marketId): ?string
    {
        return is_numeric($marketId) ? PredictMarketDetailPage::getUrl(['market' => (int) $marketId]) : null;
    }
}
