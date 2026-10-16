<?php

namespace App\Livewire;

use App\Concerns\HandlesBetShopWrites;
use App\Concerns\VoidsShopFloatPurchases;
use App\Filament\Pages\BetShopDetailPage;
use App\Filament\Pages\UnmatchedDepositsPage;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A bet shop's float purchases on its Float purchases tab, from
 * `GET /bet-shops/{id}/float-purchases`: paybill payments credited to its float,
 * newest first, voided ones included. Users with `bet-shops.float` void a purchase
 * credited to the wrong shop here. Mounted lazily, so nothing is fetched until the
 * tab is opened.
 */
class ShopFloatPurchasesTable extends TableWidget
{
    use HandlesBetShopWrites;
    use VoidsShopFloatPurchases;

    /**
     * Dispatched when the shop's float changes, so both float tabs reload.
     */
    public const REFRESH_EVENT = 'bet-shop-float-changed';

    #[Locked]
    public int $shopId;

    #[Locked]
    public string $shopNo = '';

    public bool $apiError = false;

    protected int|string|array $columnSpan = 'full';

    public function mount(int $shopId, string $shopNo = ''): void
    {
        $this->shopId = $shopId;
        $this->shopNo = $shopNo;
    }

    #[On(self::REFRESH_EVENT)]
    public function refreshPurchases(): void
    {
        $this->flushCachedTableRecords();
    }

    public static function statusColor(?string $status): string
    {
        return $status === 'active' ? 'success' : 'gray';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Float purchases')
            ->description('Paybill payments credited to this shop\'s float. Voiding one sends its deposit back to the unmatched queue.')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->fetchPurchases((int) $page, (int) $recordsPerPage),
            ))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Credited')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->description(fn (array $record): ?string => $record['recorded_by_name'] ?? null),
                TextColumn::make('amount')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('trans_id')
                    ->label('M-Pesa ID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->description(fn (array $record): ?string => $record['bill_ref_no'] ?? null),
                TextColumn::make('note')
                    ->limit(50)
                    ->tooltip(fn (array $record): ?string => $record['note'] ?? null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                TextColumn::make('voided_at')
                    ->label('Voided')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->placeholder('—')
                    ->description(fn (array $record): ?string => filled($record['voided_at'] ?? null)
                        ? collect([$record['voided_by_name'] ?? null, $record['void_reason'] ?? null])->filter()->implode(' · ')
                        : null),
            ])
            ->recordActions([
                $this->voidShopFloatPurchaseAction(fn (array $record): ?array => ($record['status'] ?? null) === 'active'
                    ? ['id' => (int) $record['id'], 'amount' => $record['amount'] ?? 0, 'shop_no' => $this->shopNo !== '' ? $this->shopNo : null]
                    : null),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Could not be loaded from KadiApi' : 'No float bought yet')
            ->emptyStateDescription(fn (): ?string => $this->apiError
                ? 'Refresh the page to try again.'
                : 'The owner buys float by paying the paybill with the bill ref above. Credit the payment from the Unmatched Deposits page.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchPurchases(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listShopFloatPurchases($this->shopId, [
                'page' => max(1, $page),
                'per_page' => min(200, max(1, $perPage)),
            ]);
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Shop float purchases failed', ['shop' => $this->shopId, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->values();

        $ids = $items->pluck('id')->all();
        $creditors = static::gmsUsersFor('ShopFloatPurchase', $ids, 'credited');
        $voiders = static::gmsUsersFor('ShopFloatPurchase', $ids, 'voided');

        $data['items'] = $items
            ->map(fn (array $row): array => [
                ...$row,
                ArrayRecord::getKeyName() => (string) $row['id'],
                'recorded_by_name' => $creditors[(int) $row['id']] ?? UnmatchedDepositsPage::resolvedBy($row['recorded_by'] ?? null),
                'voided_by_name' => $voiders[(int) $row['id']] ?? UnmatchedDepositsPage::resolvedBy($row['voided_by'] ?? null),
            ])
            ->all();

        return $data;
    }

    protected function afterBetShopWrite(): void
    {
        $this->flushCachedTableRecords();

        // The shop page shows the float balance.
        $this->dispatch(BetShopDetailPage::REFRESH_EVENT);
    }

    public function placeholder(): View
    {
        return view('livewire.customer-referral-placeholder');
    }
}
