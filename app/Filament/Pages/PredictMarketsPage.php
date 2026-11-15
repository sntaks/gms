<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesPredictWrites;
use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\ApiAuditLog;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use App\Support\PredictMarketForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The Kadi API's prediction markets (`GET /predict/admin/markets`), drafts and
 * Polymarket imports included. Admins (`predict.manage`) create drafts and
 * publish or reject them one at a time or in bulk; the market page runs the rest
 * of the lifecycle. Viewing needs `predict.view`.
 */
class PredictMarketsPage extends Page implements HasTable
{
    use HandlesPredictWrites;
    use InteractsWithTable;

    public const TABS = [
        'draft' => 'Drafts',
        'open' => 'Open',
        'suspended' => 'Suspended',
        'closed' => 'Awaiting result',
        'resolved' => 'Resolved',
        'settled' => 'Settled',
        'voided' => 'Voided',
        'rejected' => 'Rejected',
        'all' => 'All',
    ];

    public const BULK_REJECT_WARNING = 'Rejecting is permanent: a rejected Polymarket market is never imported again.';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationLabel = 'Markets';

    protected static string|UnitEnum|null $navigationGroup = '🔮 Predictions';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'predictions/markets';

    protected ?string $heading = 'Prediction markets';

    protected ?string $subheading = 'Markets on the Kadi API. Multipliers on pool markets are estimates. Times in Nairobi time.';

    protected string $view = 'filament.pages.predict-markets-page';

    #[Url]
    public string $tab = 'draft';

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return static::canViewPredict();
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'open' => 'success',
            'draft' => 'warning',
            'suspended', 'closed' => 'info',
            'resolved', 'settled' => 'primary',
            'rejected', 'voided' => 'gray',
            default => 'gray',
        };
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'draft';
        }
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS)) {
            return;
        }

        $this->tab = $tab;
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->createMarketAction(),
            $this->bulkAction('publish'),
            $this->bulkAction('reject'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->fetchMarkets((int) $page, (int) $recordsPerPage),
            ))
            ->columns([
                TextColumn::make('question')
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (array $record): string => implode(' · ', array_filter([
                        config('predict.categories.'.($record['category'] ?? '')) ?? ($record['category'] ?? null),
                        config('predict.sources.'.($record['source'] ?? '')),
                        config('predict.engines.'.($record['engine'] ?? '')),
                    ]))),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => config('predict.statuses.'.$state) ?? ucfirst((string) $state))
                    ->description(fn (array $record): ?string => $record['reject_reason'] ?? null),
                TextColumn::make('close_at')
                    ->label('Closes')
                    ->formatStateUsing(fn (?string $state): string => Format::dateTime($state)),
                TextColumn::make('odds')
                    ->label('Yes / No')
                    ->state(fn (array $record): ?string => static::oddsLine($record['odds'] ?? null))
                    ->placeholder('—'),
                TextColumn::make('volume')
                    ->label('Volume')
                    ->alignEnd()
                    ->state(fn (array $record): string => isset($record['external']['volume'])
                        ? 'USD '.Format::compact($record['external']['volume'])
                        : Format::money($record['odds']['volume'] ?? 0))
                    ->tooltip(fn (array $record): ?string => isset($record['external']['volume']) ? 'Polymarket trading volume' : 'Staked by players'),
            ])
            ->filters([
                SelectFilter::make('source')->options(config('predict.sources')),
                SelectFilter::make('category')->options(config('predict.categories')),
            ])
            ->recordActions([
                $this->rowAction('publish'),
                $this->rowAction('reject'),
            ])
            ->recordUrl(fn (array $record): string => PredictMarketDetailPage::getUrl(['market' => (int) $record['id']]))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('heroicon-o-light-bulb')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Markets unavailable' : 'No markets')
            ->emptyStateDescription(fn (): ?string => $this->apiError ? 'KadiApi could not be reached. Refresh the page to try again.' : null)
            ->striped();
    }

    /**
     * "Yes 1.84x · No 2.10x" (pool multipliers are estimates).
     *
     * @param  array<string, mixed>|null  $odds
     */
    public static function oddsLine(?array $odds): ?string
    {
        if ($odds === null || ! isset($odds['yes_multiplier'], $odds['no_multiplier'])) {
            return null;
        }

        return ($odds['yes_label'] ?? 'Yes').' '.number_format((float) $odds['yes_multiplier'], 2).'x · '
            .($odds['no_label'] ?? 'No').' '.number_format((float) $odds['no_multiplier'], 2).'x';
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchMarkets(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listPredictMarkets([
                'status' => $this->tab === 'all' ? null : $this->tab,
                'source' => $this->tableFilters['source']['value'] ?? null,
                'category' => $this->tableFilters['category']['value'] ?? null,
                'sort' => $this->tab === 'open' ? 'closing_soon' : 'new',
                'page' => max(1, $page),
                'per_page' => min(100, max(1, $perPage)),
            ]);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('Prediction markets list failed', ['tab' => $this->tab, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['id']])
            ->values()
            ->all();

        return $data;
    }

    protected function rowAction(string $verb): Action
    {
        $publish = $verb === 'publish';

        return Action::make($verb.'Market')
            ->label($publish ? 'Publish' : 'Reject')
            ->icon($publish ? 'heroicon-o-rocket-launch' : 'heroicon-o-x-mark')
            ->color($publish ? 'success' : 'danger')
            ->visible(fn (array $record): bool => static::canManagePredict() && ($record['status'] ?? null) === 'draft')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => ($publish ? 'Publish' : 'Reject').': '.($record['question'] ?? 'market'))
            ->modalDescription($publish
                ? 'Players can bet on it at once. An imported market opens at its Polymarket price, read now; with no usable price it is refused.'
                : self::BULK_REJECT_WARNING)
            ->schema($publish ? [] : [Textarea::make('reason')->required()->maxLength(255)->rows(2)])
            ->action(function (array $record, array $data, Action $action) use ($verb, $publish): void {
                abort_unless(static::canManagePredict(), 403);

                $id = (int) $record['id'];
                $payload = array_filter(['admin_user' => static::adminUser(), 'reason' => isset($data['reason']) ? trim($data['reason']) : null]);
                $market = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->predictMarketAction($id, $verb, $payload), 'PredictMarket', $id, $verb, $payload, $publish ? 'Could not publish' : 'Could not reject', ['reason' => 'reason']);

                if ($market !== null) {
                    Notification::make()->title($publish ? 'Published' : 'Rejected')->success()->send();
                    $this->afterPredictWrite();
                }
            });
    }

    protected function createMarketAction(): Action
    {
        return Action::make('createMarket')
            ->label('New market')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => static::canManagePredict())
            ->modalHeading('Draft a prediction market')
            ->modalDescription('Players never see a draft. Publish it once it reads right.')
            ->modalSubmitActionLabel('Save draft')
            ->modalWidth('3xl')
            ->fillForm(fn (): array => ['engine' => 'pool', 'countries' => array_keys(config('predict.countries'))])
            ->schema(PredictMarketForm::draftFields(creating: true))
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canManagePredict(), 403);

                $payload = ['admin_user' => static::adminUser(), ...PredictMarketForm::payload($data)];
                $market = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->createPredictMarket($payload), 'PredictMarket', 0, 'create', $payload, 'Could not save the draft', PredictMarketForm::FIELDS);

                if ($market === null) {
                    return;
                }

                Notification::make()->title('Draft saved')->success()->send();

                if (isset($market['id'])) {
                    $this->redirect(PredictMarketDetailPage::getUrl(['market' => (int) $market['id']]), navigate: true);
                }
            });
    }

    /**
     * Publish or reject every draft matching a filter, oldest first, walking the API's
     * `after_id` cursor until `next_after_id` is null so refused drafts are passed over.
     */
    protected function bulkAction(string $verb): Action
    {
        $publish = $verb === 'publish';

        return Action::make('bulk'.ucfirst($verb))
            ->label($publish ? 'Publish drafts' : 'Reject drafts')
            ->icon($publish ? 'heroicon-o-rocket-launch' : 'heroicon-o-x-mark')
            ->color($publish ? 'success' : 'danger')
            ->outlined()
            ->visible(fn (): bool => static::canManagePredict() && $this->tab === 'draft')
            ->modalHeading($publish ? 'Publish matching drafts' : 'Reject matching drafts')
            ->modalDescription($publish
                ? 'Drafts that cannot open (e.g. no usable Polymarket price) are refused one by one and left as drafts.'
                : self::BULK_REJECT_WARNING)
            ->modalSubmitActionLabel($publish ? 'Publish' : 'Reject')
            ->schema(array_values(array_filter([
                Select::make('source')->options(config('predict.sources'))->placeholder('Any source'),
                Select::make('category')->options(config('predict.categories'))->placeholder('Any category'),
                TextInput::make('min_volume')->label('Polymarket volume from (USD)')->numeric()->minValue(0),
                TextInput::make('max_volume')->label('Polymarket volume up to (USD)')->numeric()->minValue(0),
                $publish ? null : Textarea::make('reason')->required()->maxLength(255)->rows(2),
            ])))
            ->action(function (array $data) use ($publish): void {
                abort_unless(static::canManagePredict(), 403);

                $filter = array_filter([
                    'source' => $data['source'] ?? null,
                    'category' => $data['category'] ?? null,
                    'min_volume' => filled($data['min_volume'] ?? null) ? (float) $data['min_volume'] : null,
                    'max_volume' => filled($data['max_volume'] ?? null) ? (float) $data['max_volume'] : null,
                ], fn ($value): bool => $value !== null);

                $result = $this->runBulk($publish ? 'publish' : 'reject', $filter, $publish ? null : trim((string) $data['reason']));

                $notification = Notification::make()
                    ->title(($publish ? 'Published ' : 'Rejected ').$result['done'].' '.str('draft')->plural($result['done']))
                    ->body(static::bulkSummary($result))
                    ->persistent();

                ($result['error'] === null ? $notification->success() : $notification->warning())->send();

                $this->afterPredictWrite();
            });
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{done: int, refused: array<string, int>, calls: int, finished: bool, error: string|null}
     */
    public function runBulk(string $action, array $filter, ?string $reason): array
    {
        $done = 0;
        $refused = [];
        $afterId = null;
        $calls = 0;
        $error = null;
        $finished = false;

        while ($calls < (int) config('predict.bulk_max_calls')) {
            $payload = array_filter([
                'admin_user' => static::adminUser(),
                'reason' => $reason,
                'filter' => $filter === [] ? (object) [] : $filter,
                'limit' => (int) config('predict.bulk_limit'),
                'after_id' => $afterId,
            ], fn ($value): bool => $value !== null);

            $calls++;

            try {
                $result = app(GameApiService::class)->bulkPredictMarkets($action, $payload);
            } catch (GameApiException $e) {
                $error = $e->statusCode === 0 || $e->statusCode >= 500
                    ? self::UNKNOWN_OUTCOME
                    : ($e->apiMessage !== '' ? $e->apiMessage : $e->getMessage());
                ApiAuditLog::record('PredictMarket', 0, 'bulk_'.$action, $payload, $e->statusCode, ['message' => $error, 'code' => $e->errorCode]);

                break;
            }

            $done += count($result['done'] ?? []);

            foreach ($result['refused'] ?? [] as $refusal) {
                $code = (string) ($refusal['code'] ?? 'refused');
                $refused[$code] = ($refused[$code] ?? 0) + 1;
            }

            ApiAuditLog::record('PredictMarket', 0, 'bulk_'.$action, $payload, 200, [
                'done' => count($result['done'] ?? []),
                'refused' => count($result['refused'] ?? []),
                'next_after_id' => $result['next_after_id'] ?? null,
            ]);

            $afterId = $result['next_after_id'] ?? null;

            if ($afterId === null) {
                $finished = true;

                break;
            }
        }

        return ['done' => $done, 'refused' => $refused, 'calls' => $calls, 'finished' => $finished, 'error' => $error];
    }

    /**
     * @param  array{done: int, refused: array<string, int>, calls: int, finished: bool, error: string|null}  $result
     */
    public static function bulkSummary(array $result): string
    {
        $parts = [];

        if ($result['refused'] !== []) {
            $parts[] = 'Refused and left as drafts: '.collect($result['refused'])
                ->map(fn (int $count, string $code): string => str_replace('_', ' ', $code).' ×'.$count)
                ->implode(', ').'.';
        }

        if ($result['error'] !== null) {
            $parts[] = 'Stopped: '.$result['error'];
        } elseif (! $result['finished']) {
            $parts[] = 'Stopped after '.$result['calls'].' calls with drafts left: run it again to continue.';
        }

        return $parts === [] ? 'Every matching draft was done.' : implode(' ', $parts);
    }

    protected function afterPredictWrite(): void
    {
        $this->flushCachedTableRecords();
    }
}
