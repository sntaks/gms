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
 * A bet shop's API keys on its Keys tab, from `GET /bet-shops/{id}/keys`, newest
 * first. The key itself is never returned again; `key_hint` is its last 4
 * characters. Admins with `bet-shops.manage` revoke keys here. Mounted lazily, so
 * nothing is fetched until the tab is opened.
 */
class BetShopKeysTable extends TableWidget
{
    use HandlesBetShopWrites;

    public const REFRESH_EVENT = 'bet-shop-keys-changed';

    #[Locked]
    public int $shopId;

    public bool $apiError = false;

    protected int|string|array $columnSpan = 'full';

    public function mount(int $shopId): void
    {
        $this->shopId = $shopId;
    }

    #[On(self::REFRESH_EVENT)]
    public function refreshKeys(): void
    {
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('API keys')
            ->description('Each key works only on the shop system routes and always acts for this shop.')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->fetchKeys((int) $page, (int) $recordsPerPage),
            ))
            ->columns([
                TextColumn::make('name')
                    ->weight('bold'),
                TextColumn::make('key_hint')
                    ->label('Ends with')
                    ->fontFamily('mono'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state): string => $state ? 'Active' : 'Revoked'),
                TextColumn::make('created_at')
                    ->label('Issued')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->description(fn (array $record): ?string => $record['issued_by_name'] ?? null),
                TextColumn::make('revoked_at')
                    ->label('Revoked')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                    ->placeholder('—')
                    ->description(fn (array $record): ?string => $record['revoked_by_name'] ?? null),
            ])
            ->recordActions([
                $this->revokeKeyAction(),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon('heroicon-o-key')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Could not be loaded from KadiApi' : 'No keys issued')
            ->emptyStateDescription(fn (): ?string => $this->apiError ? 'Refresh the page to try again.' : 'Issue a key from the top of the page for the shop\'s desktop system.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchKeys(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listBetShopKeys($this->shopId, [
                'page' => max(1, $page),
                'per_page' => min(200, max(1, $perPage)),
            ]);
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Bet shop keys failed', ['shop' => $this->shopId, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->values();

        $ids = $items->pluck('id')->all();
        $issuers = static::gmsUsers($ids, 'issued');
        $revokers = static::gmsUsers($ids, 'revoked');

        $data['items'] = $items
            ->map(fn (array $row): array => [
                ...$row,
                ArrayRecord::getKeyName() => (string) $row['id'],
                'issued_by_name' => $issuers[(int) $row['id']] ?? null,
                'revoked_by_name' => $revokers[(int) $row['id']] ?? UnmatchedDepositsPage::resolvedBy($row['revoked_by'] ?? null),
            ])
            ->all();

        return $data;
    }

    /**
     * The GMS user who issued or revoked each key. KadiApi records only the API key.
     *
     * @param  list<int|string>  $keyIds
     * @return array<int, string>
     */
    public static function gmsUsers(array $keyIds, string $event): array
    {
        return static::gmsUsersFor('BetShopKey', $keyIds, $event);
    }

    protected function revokeKeyAction(): Action
    {
        return Action::make('revokeKey')
            ->label('Revoke')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canManageBetShops() && ($record['is_active'] ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => 'Revoke '.($record['name'] ?? 'this key').'?')
            ->modalDescription(fn (array $record): string => 'The key ending '.($record['key_hint'] ?? '').' stops working at once. The shop\'s system needs a new key to keep working.')
            ->modalSubmitActionLabel('Revoke key')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([Hidden::make('idempotency_key')])
            ->action(function (array $data, array $record, Action $action): void {
                abort_unless(static::canManageBetShops(), 403);

                $keyId = (int) $record['id'];
                $payload = ['bet_shop_id' => $this->shopId, 'name' => $record['name'] ?? null, 'idempotency_key' => $data['idempotency_key']];

                try {
                    $key = app(GameApiService::class)->revokeBetShopKey($keyId, $data['idempotency_key']);
                } catch (GameApiException $e) {
                    static::recordBetShopWrite('BetShopKey', $keyId, 'revoke_failed', $payload, $e->statusCode, static::betShopErrorResponse($e));

                    $this->handleBetShopWriteFailure($e, $action, 'Could not revoke the key');

                    return;
                }

                static::recordBetShopWrite('BetShopKey', $keyId, 'revoked', $payload, 200, $key);

                Notification::make()
                    ->title('Key '.($record['name'] ?? '').' revoked')
                    ->success()
                    ->send();

                $this->afterBetShopWrite();
            });
    }

    protected function afterBetShopWrite(): void
    {
        $this->flushCachedTableRecords();

        // The shop page shows how many keys are active.
        $this->dispatch(BetShopDetailPage::REFRESH_EVENT);
    }

    public function placeholder(): View
    {
        return view('livewire.customer-referral-placeholder');
    }
}
