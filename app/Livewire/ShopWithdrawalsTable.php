<?php

namespace App\Livewire;

use App\Concerns\HandlesBetShopWrites;
use App\Filament\Pages\ReferralWithdrawalsPage;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
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
 * Cash withdrawals at bet shops, read-only: a player asks for one in the app, gets a
 * pickup code, and the shop pays the cash once the code is right. From
 * `GET /bet-shops/withdrawals` (every shop), `GET /bet-shops/{id}/withdrawals` (one
 * shop) or `GET /customers/{id}/shop-withdrawals` (one player). The GMS never sees
 * or resets pickup codes. A pending request well past its expiry is flagged: the
 * expiry job should have released it, so the scheduler is probably not running.
 */
class ShopWithdrawalsTable extends TableWidget
{
    use HandlesBetShopWrites;

    public const STATUSES = [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ];

    /**
     * The expiry job runs every 5 minutes, so a request is only flagged once it is this far past expiry.
     */
    public const STALE_AFTER_MINUTES = 15;

    public const STALE_WARNING = 'Past expiry: the shop-withdrawals:expire scheduler may not be running, so the money is still held.';

    #[Locked]
    public ?int $shopId = null;

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public ?string $initialStatus = null;

    public bool $apiError = false;

    protected int|string|array $columnSpan = 'full';

    public function mount(?int $shopId = null, ?int $customerId = null, ?string $initialStatus = null): void
    {
        $this->shopId = $shopId;
        $this->customerId = $customerId;
        $this->initialStatus = array_key_exists((string) $initialStatus, self::STATUSES) ? $initialStatus : null;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'paid' => 'success',
            'rejected' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Whether a pending request is more than {@see STALE_AFTER_MINUTES} past its expiry.
     *
     * @param  array<string, mixed>  $withdrawal
     */
    public static function isStale(array $withdrawal): bool
    {
        if (($withdrawal['status'] ?? null) !== 'pending') {
            return false;
        }

        return Format::toCarbon($withdrawal['expires_at'] ?? null)?->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->customerId !== null ? 'Shop withdrawals' : null)
            ->description($this->customerId !== null ? 'Cash this player asked to collect at a bet shop. Pending requests hold the money until they are paid, cancelled or expire.' : null)
            ->records(fn (array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->fetchWithdrawals($filters, (int) $page, (int) $recordsPerPage),
            ))
            ->columns([
                TextColumn::make('requested_at')
                    ->label('Requested')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
                TextColumn::make('withdrawal_id')
                    ->label('Withdrawal')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (?string $state): string => Str::limit((string) $state, 8, '…'))
                    ->tooltip(fn (array $record): ?string => $record['withdrawal_id'] ?? null)
                    ->copyable()
                    ->copyableState(fn (array $record): ?string => $record['withdrawal_id'] ?? null),
                TextColumn::make('shop_no')
                    ->label('Shop')
                    ->fontFamily('mono')
                    ->description(fn (array $record): ?string => $record['shop_name'] ?? null)
                    ->url(fn (array $record): ?string => ShopLoadsTable::shopUrl($record['bet_shop_id'] ?? null))
                    ->visible(fn (): bool => $this->shopId === null),
                TextColumn::make('customer_name')
                    ->label('Player')
                    ->placeholder('—')
                    ->description(fn (array $record): string => Format::playerNo($record['player']['player_no'] ?? $record['player_no'] ?? null))
                    ->url(fn (array $record): ?string => ReferralWithdrawalsPage::customerUrl($record['customer_id'] ?? null))
                    ->visible(fn (): bool => $this->customerId === null),
                TextColumn::make('amount')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state, array $record): string => static::isStale($record) ? 'danger' : static::statusColor($state))
                    ->icon(fn (array $record): ?string => static::isStale($record) ? 'heroicon-m-exclamation-triangle' : null)
                    ->tooltip(fn (array $record): ?string => static::isStale($record) ? self::STALE_WARNING : null)
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('failed_attempts')
                    ->label('Wrong codes')
                    ->alignEnd()
                    ->numeric()
                    ->color(fn ($state): ?string => (int) $state > 0 ? 'warning' : null)
                    ->visible(fn (): bool => $this->customerId === null),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->color(fn (array $record): ?string => static::isStale($record) ? 'danger' : null)
                    ->weight(fn (array $record): ?string => static::isStale($record) ? 'bold' : null),
                TextColumn::make('closed_at')
                    ->label('Closed')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->placeholder('—')
                    ->description(fn (array $record): ?string => collect([static::actorLabel($record['closed_by'] ?? null), $record['close_reason'] ?? null])->filter()->implode(' · ') ?: null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::STATUSES)
                    ->default(fn (): ?string => $this->initialStatus),
                Filter::make('requested')
                    ->label('Requested between')
                    ->schema([
                        DatePicker::make('from')->label('Requested from'),
                        DatePicker::make('to')->label('Requested to'),
                    ])
                    ->visible(fn (): bool => $this->customerId === null),
                Filter::make('customer')
                    ->schema([
                        TextInput::make('customer_id')->label('Customer ID')->integer()->minValue(1),
                    ])
                    ->visible(fn (): bool => $this->customerId === null),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption($this->shopId === null && $this->customerId === null ? 50 : 10)
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Could not be loaded from KadiApi' : 'No cash withdrawals')
            ->emptyStateDescription(fn (): ?string => $this->apiError ? 'Refresh the page to try again.' : 'Try a different status or period.')
            ->striped();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function fetchWithdrawals(array $filters, int $page, int $perPage): array
    {
        $paging = ['page' => max(1, $page), 'per_page' => min(200, max(1, $perPage))];
        $status = $filters['status']['value'] ?? null;
        $gameApi = app(GameApiService::class);

        try {
            $data = $this->customerId !== null
                ? $gameApi->listCustomerShopWithdrawals($this->customerId, ['status' => $status, ...$paging])
                : $gameApi->listShopWithdrawals($this->shopId, [
                    'status' => $status,
                    'customer_id' => filled($filters['customer']['customer_id'] ?? null) ? (int) $filters['customer']['customer_id'] : null,
                    'from' => filled($filters['requested']['from'] ?? null) ? Carbon::parse($filters['requested']['from'])->format('Y-m-d') : null,
                    'to' => filled($filters['requested']['to'] ?? null) ? Carbon::parse($filters['requested']['to'])->format('Y-m-d') : null,
                    ...$paging,
                ]);
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Shop withdrawals failed', ['shop' => $this->shopId, 'customer' => $this->customerId, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && filled($row['withdrawal_id'] ?? null))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['withdrawal_id']])
            ->values()
            ->all();

        return $data;
    }

    protected function afterBetShopWrite(): void
    {
        $this->flushCachedTableRecords();
    }

    public function placeholder(): View
    {
        return view('livewire.customer-referral-placeholder');
    }
}
