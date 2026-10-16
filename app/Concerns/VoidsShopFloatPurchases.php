<?php

namespace App\Concerns;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\Format;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Void a float purchase: KadiApi takes the amount back out of the shop's float and
 * puts the deposit back to unmatched, so a payment credited to the wrong shop can be
 * credited again or assigned. Used from the unmatched queue's Shop float tab and from
 * the shop's Float purchases tab. Needs {@see HandlesBetShopWrites}.
 */
trait VoidsShopFloatPurchases
{
    public const VOID_PURCHASE_WARNING = 'The amount comes back out of the shop\'s float and the deposit returns to the unmatched queue, where it can be credited to the right shop or assigned.';

    /**
     * `$purchaseOf` gives the float purchase a row stands for, or null when it has none to void.
     *
     * @param  Closure(array<string, mixed>): ?array{id: int, amount: mixed, shop_no: ?string}  $purchaseOf
     */
    protected function voidShopFloatPurchaseAction(Closure $purchaseOf): Action
    {
        return Action::make('voidFloatPurchase')
            ->label('Void float purchase')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canManageShopFloat() && $purchaseOf($record) !== null)
            ->modalHeading(fn (array $record): string => 'Void '.Format::money($purchaseOf($record)['amount'] ?? 0).' credited to '.($purchaseOf($record)['shop_no'] ?? 'the shop').'?')
            ->modalDescription(self::VOID_PURCHASE_WARNING)
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Void purchase')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                Textarea::make('reason')
                    ->label('Why is it voided?')
                    ->placeholder('Credited to the wrong shop')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (array $data, array $record, Action $action) use ($purchaseOf): void {
                abort_unless(static::canManageShopFloat(), 403);

                $purchase = $purchaseOf($record);

                if ($purchase === null) {
                    return;
                }

                $payload = ['reason' => trim($data['reason']), 'shop_no' => $purchase['shop_no'], 'idempotency_key' => $data['idempotency_key']];

                try {
                    $voided = app(GameApiService::class)->voidShopFloatPurchase($purchase['id'], $payload['reason'], $data['idempotency_key']);
                } catch (GameApiException $e) {
                    static::recordBetShopWrite('ShopFloatPurchase', $purchase['id'], 'void_failed', $payload, $e->statusCode, [...static::betShopErrorResponse($e), 'float_balance' => $e->body['float_balance'] ?? null]);

                    $this->handleBetShopWriteFailure($e, $action, 'Could not void the float purchase', ['reason' => 'reason'], [
                        'insufficient_float' => 'The float no longer holds this amount',
                        'already_resolved' => 'Float purchase already voided',
                    ]);

                    return;
                }

                static::recordBetShopWrite('ShopFloatPurchase', $purchase['id'], 'voided', $payload, 200, $voided);

                Notification::make()
                    ->title('Float purchase voided')
                    ->body(Format::money($purchase['amount']).' was taken off '.($purchase['shop_no'] ?? 'the shop').'\'s float. The deposit is back in the unmatched queue.')
                    ->success()
                    ->send();

                $this->afterBetShopWrite();
            });
    }
}
