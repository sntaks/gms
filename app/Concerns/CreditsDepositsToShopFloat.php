<?php

namespace App\Concerns;

use App\Exceptions\GameApiException;
use App\Filament\Pages\BetShopDetailPage;
use App\Filament\Pages\BetShopsPage;
use App\Services\GameApiService;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

/**
 * Credit an unmatched paybill deposit to a bet shop's float, and void it again from
 * the queue's Shop float tab. Owners buy float with bill ref `SHOP-<shop_no>`;
 * KadiApi names the shop in `bet_shop`, but owners mistype, so any open shop can be
 * chosen. Callbacks are not authenticated yet, so the payment must be checked on the
 * M-Pesa statement first. Used by the unmatched deposits page, together with
 * {@see ResolvesUnmatchedDeposits} and {@see HandlesBetShopWrites}.
 */
trait CreditsDepositsToShopFloat
{
    use VoidsShopFloatPurchases;

    public const SHOP_FLOAT_WARNING = 'Check this payment on the paybill M-Pesa statement first. Callbacks are not authenticated yet, so a deposit can be forged.';

    protected function afterBetShopWrite(): void
    {
        $this->afterDepositResolved();
    }

    /**
     * Open shops as select options, "S101 · Kariobangi Bet Shop", with suspended ones marked.
     * Closed shops cannot be credited.
     *
     * @return array<string, string>
     */
    public static function shopFloatOptions(): array
    {
        try {
            $shops = app(GameApiService::class)->betShopOptions();
        } catch (GameApiException) {
            return [];
        }

        return collect($shops)
            ->reject(fn (array $shop): bool => $shop['status'] === 'closed')
            ->mapWithKeys(fn (array $shop): array => [
                $shop['shop_no'] => $shop['shop_no'].' · '.$shop['name'].($shop['status'] === 'suspended' ? ' (suspended)' : ''),
            ])
            ->all();
    }

    /**
     * The shop KadiApi matched from the bill ref, unless it is closed.
     *
     * @param  array<string, mixed>  $deposit
     */
    protected static function suggestedShopNo(array $deposit): ?string
    {
        $shop = is_array($deposit['bet_shop'] ?? null) ? $deposit['bet_shop'] : null;

        return $shop !== null && ($shop['status'] ?? null) !== 'closed' ? ($shop['shop_no'] ?? null) : null;
    }

    protected function creditShopFloatAction(): Action
    {
        return Action::make('creditShopFloat')
            ->label('Credit to shop float')
            ->icon('heroicon-o-building-storefront')
            ->color(fn (array $record): string => is_array($record['bet_shop'] ?? null) ? 'success' : 'gray')
            ->visible(fn (array $record): bool => static::canManageShopFloat() && ($record['status'] ?? 'unmatched') === 'unmatched')
            ->modalHeading(fn (array $record): string => 'Credit '.Format::money($record['amount'] ?? 0).' to a shop\'s float')
            ->modalDescription(self::SHOP_FLOAT_WARNING)
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('warning')
            ->modalSubmitActionLabel('Credit float')
            ->fillForm(fn (array $record): array => [
                // Generated here, not as a default: fillForm() replaces field defaults.
                'idempotency_key' => Str::uuid()->toString(),
                'shop_no' => static::suggestedShopNo($record),
            ])
            ->schema(fn (array $record): array => [
                Hidden::make('idempotency_key'),
                TextEntry::make('payment')
                    ->label('Payment')
                    ->state('Bill ref '.($record['bill_ref_no'] ?? '—').' · paid by '.($record['name'] ?? 'unknown').' · M-Pesa '.($record['trans_id'] ?? '—')),
                Select::make('shop_no')
                    ->label('Shop to credit')
                    ->options(fn (): array => static::shopFloatOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText(fn (): string => static::suggestedShopNo($record) !== null
                        ? 'Matched from the bill ref. Choose another shop if the owner mistyped it.'
                        : 'No open shop matches this bill ref. Choose the shop the owner paid for.'),
                TextEntry::make('credit_confirmation')
                    ->hiddenLabel()
                    ->state(fn (Get $get): ?string => filled($get('shop_no'))
                        ? Format::money($record['amount'] ?? 0).' will be added to '.$get('shop_no').'\'s float in full: no excise duty. This can be voided later.'
                        : null)
                    ->color('warning')
                    ->weight('bold')
                    ->visible(fn (Get $get): bool => filled($get('shop_no'))),
                Textarea::make('note')
                    ->label('What did you check?')
                    ->placeholder('Checked on the paybill M-Pesa statement')
                    ->helperText('Saved on the deposit with your name.')
                    ->required()
                    ->minLength(3)
                    ->maxLength(fn (): int => self::RESOLVE_NOTE_MAX_LENGTH - mb_strlen($this->resolveNoteSignature()))
                    ->rows(2),
            ])
            ->action(function (array $data, array $record, Action $action): void {
                abort_unless(static::canManageShopFloat(), 403);

                $depositId = (int) $record['id'];
                $payload = ['shop_no' => (string) $data['shop_no'], 'note' => trim($data['note']).$this->resolveNoteSignature()];

                try {
                    $result = app(GameApiService::class)->creditDepositToShopFloat($depositId, $payload['shop_no'], $payload['note'], $data['idempotency_key']);
                } catch (GameApiException $e) {
                    static::recordBetShopWrite('Deposit', $depositId, 'shop_float_failed', [...$payload, 'idempotency_key' => $data['idempotency_key']], $e->statusCode, static::betShopErrorResponse($e));

                    $this->handleBetShopWriteFailure($e, $action, 'Could not credit the shop float', ['shop_no' => 'shop_no', 'note' => 'note'], [
                        'already_resolved' => 'Deposit already resolved',
                        'shop_closed' => 'The shop is closed',
                        'deposit_not_found' => 'Deposit not found',
                    ]);

                    return;
                }

                $purchase = $result['float_purchase'] ?? [];

                static::recordBetShopWrite('Deposit', $depositId, 'shop_float', [...$payload, 'idempotency_key' => $data['idempotency_key']], 200, $result);
                static::recordBetShopWrite('ShopFloatPurchase', (int) ($purchase['id'] ?? 0), 'credited', [...$payload, 'deposit_id' => $depositId], 200, $purchase);

                Notification::make()
                    ->title('Shop float credited')
                    ->body(Format::money($purchase['amount'] ?? $record['amount'] ?? 0).' added to '.$payload['shop_no'].'\'s float.')
                    ->success()
                    ->send();

                $this->afterDepositResolved();
            });
    }

    /**
     * The float purchase behind a deposit resolved as `shop_float`, for voiding it from the row.
     *
     * @param  array<string, mixed>  $deposit
     * @return array{id: int, amount: mixed, shop_no: ?string}|null
     */
    protected static function resolvedFloatPurchase(array $deposit): ?array
    {
        $resolution = is_array($deposit['resolution'] ?? null) ? $deposit['resolution'] : [];

        if (($resolution['action'] ?? null) !== 'shop_float' || blank($resolution['float_purchase_id'] ?? null)) {
            return null;
        }

        return ['id' => (int) $resolution['float_purchase_id'], 'amount' => $deposit['amount'] ?? 0, 'shop_no' => $resolution['shop_no'] ?? null];
    }

    /**
     * The shop page a `shop_float` resolution credited, for whoever may open shops.
     *
     * @param  array<string, mixed>  $deposit
     */
    public static function creditedShopUrl(array $deposit): ?string
    {
        $shopId = $deposit['resolution']['bet_shop_id'] ?? null;

        return filled($shopId) && BetShopsPage::canAccess() ? BetShopDetailPage::getUrl(['shop' => (int) $shopId]) : null;
    }
}
