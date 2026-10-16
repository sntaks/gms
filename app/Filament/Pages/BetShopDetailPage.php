<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesBetShopWrites;
use App\Exceptions\GameApiException;
use App\Livewire\BetShopKeysTable;
use App\Services\GameApiService;
use App\Support\Format;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * One bet shop from KadiApi: its status, float and the bill ref the owner buys float
 * with, plus its details and API keys. Admins with `bet-shops.manage` edit, suspend,
 * activate and close the shop, and issue its keys; an issued key is shown once in a
 * modal and never kept, logged or put in a notification.
 */
class BetShopDetailPage extends Page
{
    use HandlesBetShopWrites;

    public const KEY_WARNING = 'Store this key now on the shop\'s server. It will not be shown again.';

    public const KEY_TIMEOUT_WARNING = 'The request did not finish, so a key may have been issued. Check the key list and revoke any key you did not receive.';

    public const CLOSE_WARNING = 'Closing is final: the shop can never be reactivated and all its keys are revoked. Only a shop with an empty float can be closed.';

    public const SUSPEND_WARNING = 'The shop\'s keys stop working until it is reactivated. Its float is kept, and float it buys can still be credited.';

    public const REFRESH_EVENT = 'bet-shop-changed';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'bet-shops/detail';

    protected string $view = 'filament.pages.bet-shop-detail-page';

    #[Url]
    public ?int $shop = null;

    /**
     * The shop as KadiApi last returned it. Locked so the browser cannot change what the
     * page's actions are shown or offered for.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $details = [];

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return BetShopsPage::canAccess();
    }

    public function mount(): void
    {
        abort_if($this->shop === null, 404);

        $this->loadShop();
    }

    public function getTitle(): string|Htmlable
    {
        return filled($this->details['shop_no'] ?? null)
            ? $this->details['shop_no'].' · '.($this->details['name'] ?? '')
            : 'Bet shop';
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [BetShopsPage::getUrl() => 'Bet Shops', 'Shop'];
    }

    protected function loadShop(): void
    {
        try {
            $this->details = app(GameApiService::class)->getBetShop($this->shop);
            $this->apiError = false;
        } catch (GameApiException $e) {
            abort_if($e->statusCode === 404, 404);

            Log::warning('Bet shop lookup failed', ['shop' => $this->shop, 'error' => $e->getMessage()]);
            $this->apiError = true;
        }
    }

    protected function afterBetShopWrite(): void
    {
        $this->refreshShop();

        $this->dispatch(BetShopKeysTable::REFRESH_EVENT);
    }

    /**
     * Reload the shop, e.g. after a key was revoked on the Keys tab.
     */
    #[On(self::REFRESH_EVENT)]
    public function refreshShop(): void
    {
        $this->loadShop();

        // The infolist was built from the old shop earlier in this request.
        unset($this->cachedSchemas['shopInfolist']);
    }

    protected function status(): ?string
    {
        return $this->details['status'] ?? null;
    }

    protected function isClosed(): bool
    {
        return $this->status() === 'closed';
    }

    protected function hasFloat(): bool
    {
        return (float) ($this->details['float_balance'] ?? 0) > 0;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->issueKeyAction(),
            $this->editShopAction(),
            $this->suspendShopAction(),
            $this->activateShopAction(),
            $this->closeShopAction(),
        ];
    }

    public function shopInfolist(Schema $schema): Schema
    {
        return $schema
            ->constantState(fn (): array => $this->details)
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?string $state): string => BetShopsPage::statusColor($state))
                            ->formatStateUsing(fn (?string $state): string => BetShopsPage::STATUSES[$state] ?? ucfirst((string) $state))
                            ->helperText(fn (): ?string => filled($this->details['status_reason'] ?? null) ? 'Reason: '.$this->details['status_reason'] : null),
                        TextEntry::make('float_balance')
                            ->label('Float balance')
                            ->size(TextSize::Large)
                            ->weight('bold')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                        TextEntry::make('float_bill_ref')
                            ->label('Bill ref for buying float')
                            ->size(TextSize::Large)
                            ->weight('bold')
                            ->fontFamily('mono')
                            ->color('primary')
                            ->copyable()
                            ->copyMessage('Bill ref copied')
                            ->helperText('The owner pays the paybill with this account number to buy float.'),
                    ]),
                Tabs::make('Shop')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Details')
                            ->columns(['default' => 1, 'md' => 3])
                            ->schema([
                                TextEntry::make('shop_no')
                                    ->label('Shop number')
                                    ->fontFamily('mono'),
                                TextEntry::make('name')
                                    ->label('Shop name'),
                                TextEntry::make('location')
                                    ->placeholder('—'),
                                TextEntry::make('owner_name')
                                    ->label('Owner'),
                                TextEntry::make('owner_phone')
                                    ->label('Owner phone')
                                    ->copyable(),
                                TextEntry::make('active_keys')
                                    ->label('Active keys')
                                    ->numeric(),
                                TextEntry::make('created_at')
                                    ->label('Onboarded')
                                    ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
                                TextEntry::make('updated_at')
                                    ->label('Last changed')
                                    ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
                                TextEntry::make('notes')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ]),
                        Tab::make('Keys')
                            ->badge(fn (): int => (int) ($this->details['active_keys'] ?? 0))
                            ->schema([
                                ViewEntry::make('keys')
                                    ->hiddenLabel()
                                    ->view('infolists.bet-shop-keys-tab')
                                    ->viewData(['shopId' => (int) $this->shop]),
                            ]),
                    ]),
            ]);
    }

    protected function editShopAction(): Action
    {
        return Action::make('editShop')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => static::canManageBetShops() && ! $this->apiError && ! $this->isClosed())
            ->modalHeading('Edit shop details')
            ->modalDescription(fn (): string => 'The shop number '.($this->details['shop_no'] ?? '').' never changes: the owner uses it in the bill ref.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => [
                'idempotency_key' => Str::uuid()->toString(),
                ...collect($this->details)->only(['name', 'owner_name', 'owner_phone', 'location', 'notes'])->all(),
            ])
            ->schema([
                Hidden::make('idempotency_key'),
                ...BetShopsPage::shopFormFields(creating: false),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canManageBetShops(), 403);

                $payload = BetShopsPage::shopPayload($data);

                try {
                    $shop = app(GameApiService::class)->updateBetShop((int) $this->shop, $payload, $data['idempotency_key']);
                } catch (GameApiException $e) {
                    static::recordBetShopWrite('BetShop', (int) $this->shop, 'update_failed', [...$payload, 'idempotency_key' => $data['idempotency_key']], $e->statusCode, static::betShopErrorResponse($e));

                    $this->handleBetShopWriteFailure($e, $action, 'Could not save the shop', BetShopsPage::FORM_FIELDS);

                    return;
                }

                static::recordBetShopWrite('BetShop', (int) $this->shop, 'updated', [...$payload, 'idempotency_key' => $data['idempotency_key']], 200, $shop);

                Notification::make()->title('Shop details saved')->success()->send();

                $this->afterBetShopWrite();
            });
    }

    protected function suspendShopAction(): Action
    {
        return Action::make('suspendShop')
            ->label('Suspend')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (): bool => static::canManageBetShops() && $this->status() === 'active')
            ->modalHeading(fn (): string => 'Suspend '.($this->details['shop_no'] ?? 'the shop').'?')
            ->modalDescription(self::SUSPEND_WARNING)
            ->modalIcon('heroicon-o-pause-circle')
            ->modalIconColor('warning')
            ->modalSubmitActionLabel('Suspend shop')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                static::reasonField('Why is it suspended?'),
            ])
            ->action(fn (array $data, Action $action) => $this->runStatusChange('suspend', $data, $action));
    }

    protected function activateShopAction(): Action
    {
        return Action::make('activateShop')
            ->label('Activate')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (): bool => static::canManageBetShops() && $this->status() === 'suspended')
            ->modalHeading(fn (): string => 'Reactivate '.($this->details['shop_no'] ?? 'the shop').'?')
            ->modalDescription('The shop\'s keys work again straight away.')
            ->modalSubmitActionLabel('Activate shop')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                static::reasonField('Why is it reactivated?')->required(false),
            ])
            ->action(fn (array $data, Action $action) => $this->runStatusChange('activate', $data, $action));
    }

    protected function closeShopAction(): Action
    {
        return Action::make('closeShop')
            ->label('Close')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->visible(fn (): bool => static::canManageBetShops() && ! $this->apiError && ! $this->isClosed())
            ->disabled(fn (): bool => $this->hasFloat())
            ->tooltip(fn (): ?string => $this->hasFloat()
                ? 'The float must be empty first: it holds '.Format::money($this->details['float_balance'] ?? 0).'. Record a redemption for it.'
                : null)
            ->modalHeading(fn (): string => 'Close '.($this->details['shop_no'] ?? 'the shop').' for good?')
            ->modalDescription(self::CLOSE_WARNING)
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Close shop')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                static::reasonField('Why is it closing?'),
                TextInput::make('confirm_shop_no')
                    ->label(fn (): string => 'Type the shop number ('.($this->details['shop_no'] ?? '').') to confirm')
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (strtoupper(trim((string) $value)) !== strtoupper((string) ($this->details['shop_no'] ?? ''))) {
                            $fail('That is not this shop\'s number.');
                        }
                    }),
                Checkbox::make('confirm_final')
                    ->label('I understand closing is final and cannot be undone.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirm that closing is final.']),
            ])
            ->action(fn (array $data, Action $action) => $this->runStatusChange('close', $data, $action));
    }

    protected static function reasonField(string $label): Textarea
    {
        return Textarea::make('reason')
            ->label($label)
            ->helperText('Saved on the shop as its status reason.')
            ->required()
            ->minLength(3)
            ->maxLength(255)
            ->rows(2);
    }

    /**
     * Suspend, activate or close the shop through KadiApi.
     *
     * @param  'suspend'|'activate'|'close'  $change
     * @param  array<string, mixed>  $data
     */
    protected function runStatusChange(string $change, array $data, Action $action): void
    {
        abort_unless(static::canManageBetShops(), 403);

        $shopId = (int) $this->shop;
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;
        $payload = ['reason' => $reason, 'idempotency_key' => $data['idempotency_key']];
        $gameApi = app(GameApiService::class);

        try {
            $shop = match ($change) {
                'suspend' => $gameApi->suspendBetShop($shopId, (string) $reason, $data['idempotency_key']),
                'activate' => $gameApi->activateBetShop($shopId, $reason, $data['idempotency_key']),
                'close' => $gameApi->closeBetShop($shopId, (string) $reason, $data['idempotency_key']),
            };
        } catch (GameApiException $e) {
            static::recordBetShopWrite('BetShop', $shopId, "{$change}_failed", $payload, $e->statusCode, static::betShopErrorResponse($e));

            $this->handleBetShopWriteFailure($e, $action, "Could not {$change} the shop", ['reason' => 'reason'], [
                'shop_closed' => 'The shop is closed',
                'float_not_empty' => 'The float is not empty',
            ]);

            return;
        }

        $event = ['suspend' => 'suspended', 'activate' => 'activated', 'close' => 'closed'][$change];

        static::recordBetShopWrite('BetShop', $shopId, $event, $payload, 200, $shop);

        Notification::make()
            ->title('Shop '.($shop['shop_no'] ?? $this->details['shop_no'] ?? '').' '.$event)
            ->success()
            ->send();

        $this->afterBetShopWrite();
    }

    /**
     * Issue a key for the shop's desktop system. It is sent without an Idempotency-Key, so a
     * retry would issue a second key: when the outcome is unknown the modal closes and the key
     * list is refreshed so any extra key can be revoked.
     */
    protected function issueKeyAction(): Action
    {
        return Action::make('issueKey')
            ->label('Issue key')
            ->icon('heroicon-o-key')
            ->visible(fn (): bool => static::canManageBetShops() && ! $this->apiError && ! $this->isClosed())
            ->modalHeading(fn (): string => 'Issue an API key for '.($this->details['shop_no'] ?? 'the shop'))
            ->modalDescription('The key works only on the shop system routes and always acts for this shop. It is shown once, straight after it is issued.')
            ->modalSubmitActionLabel('Issue key')
            ->schema([
                TextInput::make('name')
                    ->label('Which desk or system is it for?')
                    ->placeholder('Front desk')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canManageBetShops(), 403);

                $shopId = (int) $this->shop;
                $payload = ['name' => trim($data['name'])];

                try {
                    $issued = app(GameApiService::class)->issueBetShopKey($shopId, $payload['name']);
                } catch (GameApiException $e) {
                    static::recordBetShopWrite('BetShopKey', 0, 'issue_failed', [...$payload, 'bet_shop_id' => $shopId], $e->statusCode, static::betShopErrorResponse($e));

                    if ($e->statusCode === 0 || $e->statusCode >= 500) {
                        Notification::make()
                            ->title('The key may have been issued')
                            ->body(self::KEY_TIMEOUT_WARNING)
                            ->warning()
                            ->persistent()
                            ->send();

                        $this->afterBetShopWrite();

                        return;
                    }

                    $this->handleBetShopWriteFailure($e, $action, 'Could not issue the key', ['name' => 'name'], [
                        'shop_closed' => 'The shop is closed',
                    ]);

                    return;
                }

                static::recordBetShopWrite('BetShopKey', (int) ($issued['id'] ?? 0), 'issued', [...$payload, 'bet_shop_id' => $shopId], 201, $issued);

                $this->afterBetShopWrite();

                if (blank($issued['key'] ?? null)) {
                    Notification::make()
                        ->title('Key issued, but KadiApi did not return it')
                        ->body('Revoke key '.($issued['key_hint'] ?? '').' and issue a new one.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                // The key travels only in this modal's arguments, which are cleared when it closes.
                $this->replaceMountedAction('issuedKey', ['key' => $issued['key'], 'name' => $issued['name'] ?? $payload['name']]);
            });
    }

    /**
     * Shows a just-issued key once, with a copy button. Opened only by {@see issueKeyAction()}.
     */
    public function issuedKeyAction(): Action
    {
        return Action::make('issuedKey')
            ->visible(fn (): bool => static::canManageBetShops())
            ->modalHeading(fn (array $arguments): string => 'Key for '.($arguments['name'] ?? 'the shop'))
            ->modalDescription(self::KEY_WARNING)
            ->modalIcon('heroicon-o-key')
            ->modalIconColor('warning')
            ->schema(fn (array $arguments): array => [
                TextEntry::make('issued_key')
                    ->hiddenLabel()
                    ->state($arguments['key'] ?? '')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Key copied'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('I have stored it')
            ->closeModalByClickingAway(false);
    }
}
