<?php

namespace App\Livewire;

use App\Concerns\HandlesBetShopWrites;
use App\Exceptions\GameApiException;
use App\Filament\Pages\BetShopDetailPage;
use App\Filament\Pages\UnmatchedDepositsPage;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A bet shop's float redemptions on its Float redemptions tab, from
 * `GET /bet-shops/{id}/float-redemptions`: float paid back to the owner outside the
 * API and recorded in the GMS, newest first, voided ones included. Users with
 * `bet-shops.float` void one recorded by mistake, which puts the amount back on the
 * float. Mounted lazily, so nothing is fetched until the tab is opened.
 */
class ShopFloatRedemptionsTable extends TableWidget
{
    use HandlesBetShopWrites;

    public const VOID_WARNING = 'The amount goes back on the shop\'s float. Void a redemption only when it was recorded by mistake or the payment to the owner did not go through.';

    #[Locked]
    public int $shopId;

    public bool $apiError = false;

    protected int|string|array $columnSpan = 'full';

    public function mount(int $shopId): void
    {
        $this->shopId = $shopId;
    }

    #[On(ShopFloatPurchasesTable::REFRESH_EVENT)]
    public function refreshRedemptions(): void
    {
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Float redemptions')
            ->description('Float paid back to the owner by M-Pesa or bank and recorded here.')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->fetchRedemptions((int) $page, (int) $recordsPerPage),
            ))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->description(fn (array $record): ?string => $record['recorded_by_name'] ?? null),
                TextColumn::make('amount')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('payment_reference')
                    ->label('Payment reference')
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('note')
                    ->limit(50)
                    ->tooltip(fn (array $record): ?string => $record['note'] ?? null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => ShopFloatPurchasesTable::statusColor($state))
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
                $this->voidRedemptionAction(),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Could not be loaded from KadiApi' : 'No float paid back yet')
            ->emptyStateDescription(fn (): ?string => $this->apiError
                ? 'Refresh the page to try again.'
                : 'Pay the owner outside the GMS first, then record it with Record redemption at the top of the page.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchRedemptions(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listShopFloatRedemptions($this->shopId, [
                'page' => max(1, $page),
                'per_page' => min(200, max(1, $perPage)),
            ]);
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Shop float redemptions failed', ['shop' => $this->shopId, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->values();

        $ids = $items->pluck('id')->all();
        $recorders = static::gmsUsersFor('ShopFloatRedemption', $ids, 'recorded');
        $voiders = static::gmsUsersFor('ShopFloatRedemption', $ids, 'voided');

        $data['items'] = $items
            ->map(fn (array $row): array => [
                ...$row,
                ArrayRecord::getKeyName() => (string) $row['id'],
                'recorded_by_name' => $recorders[(int) $row['id']] ?? UnmatchedDepositsPage::resolvedBy($row['recorded_by'] ?? null),
                'voided_by_name' => $voiders[(int) $row['id']] ?? UnmatchedDepositsPage::resolvedBy($row['voided_by'] ?? null),
            ])
            ->all();

        return $data;
    }

    protected function voidRedemptionAction(): Action
    {
        return Action::make('voidRedemption')
            ->label('Void')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canManageShopFloat() && ($record['status'] ?? null) === 'active')
            ->modalHeading(fn (array $record): string => 'Void the '.Format::money($record['amount'] ?? 0).' redemption?')
            ->modalDescription(self::VOID_WARNING)
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Void redemption')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                Textarea::make('reason')
                    ->label('Why is it voided?')
                    ->placeholder('The bank transfer was returned')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (array $data, array $record, Action $action): void {
                abort_unless(static::canManageShopFloat(), 403);

                $redemptionId = (int) $record['id'];
                $payload = ['bet_shop_id' => $this->shopId, 'reason' => trim($data['reason']), 'idempotency_key' => $data['idempotency_key']];

                try {
                    $redemption = app(GameApiService::class)->voidShopFloatRedemption($redemptionId, $payload['reason'], $data['idempotency_key']);
                } catch (GameApiException $e) {
                    static::recordBetShopWrite('ShopFloatRedemption', $redemptionId, 'void_failed', $payload, $e->statusCode, static::betShopErrorResponse($e));

                    $this->handleBetShopWriteFailure($e, $action, 'Could not void the redemption', ['reason' => 'reason'], [
                        'already_resolved' => 'Redemption already voided',
                    ]);

                    return;
                }

                static::recordBetShopWrite('ShopFloatRedemption', $redemptionId, 'voided', $payload, 200, $redemption);

                Notification::make()
                    ->title('Redemption voided')
                    ->body(Format::money($record['amount'] ?? 0).' is back on the shop\'s float.')
                    ->success()
                    ->send();

                $this->afterBetShopWrite();
            });
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
