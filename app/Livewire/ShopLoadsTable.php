<?php

namespace App\Livewire;

use App\Concerns\HandlesBetShopWrites;
use App\Exceptions\GameApiException;
use App\Filament\Pages\BetShopDetailPage;
use App\Filament\Pages\BetShopsPage;
use App\Filament\Pages\ReferralWithdrawalsPage;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Cash loads at bet shops, from `GET /bet-shops/loads` (every shop) or
 * `GET /bet-shops/{id}/loads` (one shop), optionally for one customer. A player
 * hands over cash, the shop loads their wallet less 5% excise duty, and the float
 * goes down by the gross amount. Users with `bet-shops.reverse-loads` reverse a load
 * made by mistake. Used on the Shop Loads page, the shop's Loads tab and the
 * customer's Shop loads tab.
 */
class ShopLoadsTable extends TableWidget
{
    use HandlesBetShopWrites;

    public const STATUSES = [
        'completed' => 'Completed',
        'reversed' => 'Reversed',
    ];

    public const REVERSE_NOTE = 'Reverses the whole load: the player\'s wallet gives back the gross amount and the shop float is refunded.';

    #[Locked]
    public ?int $shopId = null;

    #[Locked]
    public ?int $customerId = null;

    public bool $apiError = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * Per-request memo of loads opened in the View load slide-over.
     *
     * @var array<int, array<string, mixed>|null>
     */
    protected array $openedLoads = [];

    /**
     * @param  int|null  $loadId  A load to open straight away, e.g. from an excise duty charge.
     */
    public function mount(?int $shopId = null, ?int $customerId = null, ?int $loadId = null): void
    {
        $this->shopId = $shopId;
        $this->customerId = $customerId;

        if ($loadId !== null) {
            $this->defaultAction = 'viewLoad';
            $this->defaultActionArguments = ['load' => $loadId];
        }
    }

    public static function statusColor(?string $status): string
    {
        return $status === 'completed' ? 'success' : 'gray';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->customerId !== null ? 'Shop loads' : null)
            ->description($this->customerId !== null ? 'Cash this player paid in at bet shops. The wallet got the amount less 5% excise duty.' : null)
            ->records(fn (array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->fetchLoads($filters, (int) $page, (int) $recordsPerPage),
            ))
            ->columns([
                TextColumn::make('loaded_at')
                    ->label('Loaded')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->description(fn (array $record): ?string => static::actorLabel($record['loaded_by'] ?? null)),
                TextColumn::make('load_id')
                    ->label('Load')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (?string $state): string => Str::limit((string) $state, 8, '…'))
                    ->tooltip(fn (array $record): ?string => $record['load_id'] ?? null)
                    ->copyable()
                    ->copyableState(fn (array $record): ?string => $record['load_id'] ?? null),
                TextColumn::make('shop_no')
                    ->label('Shop')
                    ->fontFamily('mono')
                    ->url(fn (array $record): ?string => static::shopUrl($record['bet_shop_id'] ?? null))
                    ->visible(fn (): bool => $this->shopId === null),
                TextColumn::make('customer_name')
                    ->label('Player')
                    ->placeholder('—')
                    ->description(fn (array $record): string => Format::playerNo($record['player']['player_no'] ?? $record['player_no'] ?? null))
                    ->url(fn (array $record): ?string => ReferralWithdrawalsPage::customerUrl($record['customer_id'] ?? null))
                    ->visible(fn (): bool => $this->customerId === null),
                TextColumn::make('gross_amount')
                    ->label('Cash paid')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('excise_amount')
                    ->label('Excise')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('net_amount')
                    ->label('To wallet')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('reversed_at')
                    ->label('Reversed')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->placeholder('—')
                    ->description(fn (array $record): ?string => filled($record['reversed_at'] ?? null)
                        ? collect([$record['reversed_by_name'] ?? null, $record['reversal_reason'] ?? null])->filter()->implode(' · ')
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::STATUSES),
                Filter::make('loaded')
                    ->label('Loaded between')
                    ->schema([
                        DatePicker::make('from')->label('Loaded from'),
                        DatePicker::make('to')->label('Loaded to'),
                    ]),
                Filter::make('customer')
                    ->schema([
                        TextInput::make('customer_id')->label('Customer ID')->integer()->minValue(1),
                    ])
                    ->visible(fn (): bool => $this->customerId === null),
            ])
            ->recordActions([
                $this->reverseLoadAction(),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption($this->shopId === null && $this->customerId === null ? 50 : 10)
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Could not be loaded from KadiApi' : 'No cash loads')
            ->emptyStateDescription(fn (): ?string => $this->apiError ? 'Refresh the page to try again.' : 'Try a different status or period.')
            ->striped();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function fetchLoads(array $filters, int $page, int $perPage): array
    {
        $query = [
            'status' => $filters['status']['value'] ?? null,
            'customer_id' => $this->customerId ?? (filled($filters['customer']['customer_id'] ?? null) ? (int) $filters['customer']['customer_id'] : null),
            'from' => static::dateFilter($filters['loaded']['from'] ?? null),
            'to' => static::dateFilter($filters['loaded']['to'] ?? null),
            'page' => max(1, $page),
            'per_page' => min(200, max(1, $perPage)),
        ];

        try {
            $data = app(GameApiService::class)->listShopLoads($this->shopId, $query);
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Shop loads failed', ['shop' => $this->shopId, 'customer' => $this->customerId, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->values();

        $reversers = static::gmsUsersFor('BetShopLoad', $items->pluck('id')->all(), 'reversed');

        $data['items'] = $items
            ->map(fn (array $row): array => [
                ...$row,
                ArrayRecord::getKeyName() => (string) $row['id'],
                'reversed_by_name' => $reversers[(int) $row['id']] ?? static::actorLabel($row['reversed_by'] ?? null),
            ])
            ->all();

        return $data;
    }

    protected static function dateFilter(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->format('Y-m-d') : null;
    }

    public static function shopUrl(int|string|null $shopId): ?string
    {
        return filled($shopId) && BetShopsPage::canAccess() ? BetShopDetailPage::getUrl(['shop' => (int) $shopId]) : null;
    }

    /**
     * One load from `GET /bet-shops/loads/{id}`, fetched once per request, or null when it
     * could not be loaded.
     *
     * @return array<string, mixed>|null
     */
    protected function openedLoad(int $loadId): ?array
    {
        if (array_key_exists($loadId, $this->openedLoads)) {
            return $this->openedLoads[$loadId];
        }

        try {
            $load = app(GameApiService::class)->getShopLoad($loadId);
        } catch (GameApiException $e) {
            Log::warning('Shop load lookup failed', ['load' => $loadId, 'error' => $e->getMessage()]);
            $load = [];
        }

        return $this->openedLoads[$loadId] = $load === [] ? null : $load;
    }

    /**
     * Opens one load in a slide-over, e.g. from an excise duty charge's `bet_shop_load_id`.
     */
    public function viewLoadAction(): Action
    {
        return Action::make('viewLoad')
            ->slideOver()
            ->modalHeading(fn (array $arguments): string => 'Cash load #'.(int) ($arguments['load'] ?? 0))
            ->modalContent(function (array $arguments): View {
                $load = $this->openedLoad((int) ($arguments['load'] ?? 0));

                return view('filament.pages.partials.shop-load-detail', [
                    'load' => $load,
                    'shopUrl' => static::shopUrl($load['bet_shop_id'] ?? null),
                    'customerUrl' => ReferralWithdrawalsPage::customerUrl($load['customer_id'] ?? null),
                    'statusLabel' => self::STATUSES[$load['status'] ?? ''] ?? ucfirst((string) ($load['status'] ?? '')),
                    'statusColor' => static::statusColor($load['status'] ?? null),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    protected function reverseLoadAction(): Action
    {
        return Action::make('reverseLoad')
            ->label('Reverse')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canReverseShopLoads() && ($record['status'] ?? null) === 'completed')
            ->modalHeading(fn (array $record): string => 'Reverse the '.Format::money($record['gross_amount'] ?? 0).' load for '.($record['customer_name'] ?? 'this player').'?')
            ->modalDescription(self::REVERSE_NOTE)
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Reverse load')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                Textarea::make('reason')
                    ->label('Why is it reversed?')
                    ->placeholder('Loaded to the wrong player')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (array $data, array $record, Action $action): void {
                abort_unless(static::canReverseShopLoads(), 403);

                $loadId = (int) $record['id'];
                $payload = [
                    'load_id' => $record['load_id'] ?? null,
                    'bet_shop_id' => $record['bet_shop_id'] ?? null,
                    'customer_id' => $record['customer_id'] ?? null,
                    'reason' => trim($data['reason']),
                    'idempotency_key' => $data['idempotency_key'],
                ];

                try {
                    $load = app(GameApiService::class)->reverseShopLoad($loadId, $payload['reason'], $data['idempotency_key']);
                } catch (GameApiException $e) {
                    static::recordBetShopWrite('BetShopLoad', $loadId, 'reverse_failed', $payload, $e->statusCode, static::betShopErrorResponse($e));

                    $this->handleBetShopWriteFailure($e, $action, 'Could not reverse the load', ['reason' => 'reason'], [
                        'player_has_spent' => 'The player has already spent this money',
                        'excise_remitted' => 'The excise duty is already remitted to KRA',
                        'already_reversed' => 'Load already reversed',
                    ]);

                    return;
                }

                static::recordBetShopWrite('BetShopLoad', $loadId, 'reversed', $payload, 200, $load);

                Notification::make()
                    ->title('Load reversed')
                    ->body(Format::money($record['gross_amount'] ?? 0).' was taken back from '.($record['customer_name'] ?? 'the player').'\'s wallet and returned to '.($record['shop_no'] ?? 'the shop').'\'s float.')
                    ->success()
                    ->send();

                $this->afterBetShopWrite();
            });
    }

    protected function afterBetShopWrite(): void
    {
        $this->flushCachedTableRecords();

        // The shop page shows the float, which a reversal refunds.
        $this->dispatch(BetShopDetailPage::REFRESH_EVENT);
    }

    public function placeholder(): View
    {
        return view('livewire.customer-referral-placeholder');
    }
}
