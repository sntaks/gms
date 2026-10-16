<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesBetShopWrites;
use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * KadiApi's physical bet shops (`GET /bet-shops`): counters where players pay in and
 * take out cash against a prepaid float the owner buys with bill ref `SHOP-<shop_no>`.
 * Viewing needs `bet-shops.view`; onboarding a shop needs `bet-shops.manage`.
 */
class BetShopsPage extends Page implements HasTable
{
    use HandlesBetShopWrites;
    use InteractsWithTable;

    public const TABS = [
        'all' => 'All',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ];

    /**
     * Form field => KadiApi field for 422 errors on the shop form.
     *
     * @var array<string, string>
     */
    public const FORM_FIELDS = [
        'shop_no' => 'shop_no',
        'name' => 'name',
        'owner_name' => 'owner_name',
        'owner_phone' => 'owner_phone',
        'location' => 'location',
        'notes' => 'notes',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Bet Shops';

    protected static string|UnitEnum|null $navigationGroup = '🏪 Bet Shops';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'bet-shops';

    protected ?string $heading = 'Bet Shops';

    protected ?string $subheading = 'Cash counters where players load their wallets and collect withdrawals against the shop\'s prepaid float.';

    protected string $view = 'filament.pages.bet-shops-page';

    #[Url]
    public string $tab = 'all';

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('bet-shops.view') ?? false;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'suspended' => 'warning',
            'closed' => 'gray',
            default => 'gray',
        };
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'all';
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
        return [$this->createBetShopAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->fetchShops((int) $page, (int) $recordsPerPage),
            ))
            ->columns([
                TextColumn::make('shop_no')
                    ->label('Shop')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->description(fn (array $record): ?string => $record['name'] ?? null),
                TextColumn::make('owner_name')
                    ->label('Owner')
                    ->placeholder('—')
                    ->description(fn (array $record): string => Format::maskedPhone($record['owner_phone'] ?? null)),
                TextColumn::make('location')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('float_balance')
                    ->label('Float')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('active_keys')
                    ->label('Active keys')
                    ->alignEnd()
                    ->numeric(),
                TextColumn::make('float_bill_ref')
                    ->label('Bill ref')
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('Bill ref copied'),
            ])
            ->recordUrl(fn (array $record): string => BetShopDetailPage::getUrl(['shop' => (int) $record['id']]))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('heroicon-o-building-storefront')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Bet shops unavailable' : 'No bet shops')
            ->emptyStateDescription(fn (): string => $this->apiError
                ? 'KadiApi could not be reached. Refresh the page to try again.'
                : ($this->tab === 'all' ? 'Onboard a shop to get started.' : 'No '.strtolower(self::TABS[$this->tab]).' shops.'))
            ->striped();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchShops(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listBetShops([
                'status' => $this->tab === 'all' ? null : $this->tab,
                'page' => max(1, $page),
                'per_page' => min(200, max(1, $perPage)),
            ]);
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Bet shops list failed', ['tab' => $this->tab, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        // Key rows by shop id so a row always opens the shop that was clicked.
        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['id']])
            ->values()
            ->all();

        return $data;
    }

    /**
     * The shop details form, shared by onboarding and editing. `shop_no` is only asked for
     * when onboarding: owners use it in their bill ref, so it never changes.
     *
     * @return list<Component|Field>
     */
    public static function shopFormFields(bool $creating): array
    {
        return array_values(array_filter([
            $creating
                ? TextInput::make('shop_no')
                    ->label('Shop number')
                    ->required()
                    ->regex('/^\s*[A-Za-z0-9]{2,10}\s*$/')
                    ->validationMessages(['regex' => 'The shop number must be 2 to 10 letters or digits.'])
                    ->helperText('2 to 10 letters or digits, saved in upper case. It can never change: the owner pays float with bill ref SHOP-<shop number>.')
                    ->placeholder('S101')
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state)))
                : null,
            TextInput::make('name')
                ->label('Shop name')
                ->required()
                ->maxLength(255),
            TextInput::make('owner_name')
                ->label('Owner name')
                ->required()
                ->maxLength(255),
            TextInput::make('owner_phone')
                ->label('Owner phone')
                ->tel()
                ->required()
                ->regex('/^\s*(?:\+?254|0)\s*[17](?:\s*[0-9]){8}\s*$/')
                ->validationMessages(['regex' => 'Enter a Kenyan mobile number, e.g. 0712345678.'])
                ->placeholder('0712345678')
                ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : preg_replace('/\s+/', '', $state)),
            TextInput::make('location')
                ->maxLength(255),
            Textarea::make('notes')
                ->maxLength(2000)
                ->rows(3),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function shopPayload(array $data): array
    {
        return collect($data)
            ->only(array_keys(self::FORM_FIELDS))
            ->map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value)
            ->all();
    }

    protected function createBetShopAction(): Action
    {
        return Action::make('createBetShop')
            ->label('Onboard shop')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => static::canManageBetShops())
            ->modalHeading('Onboard a bet shop')
            ->modalDescription('The shop starts active with an empty float. Issue it a key from the shop page once it is created.')
            ->modalSubmitActionLabel('Create shop')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                ...static::shopFormFields(creating: true),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canManageBetShops(), 403);

                $payload = static::shopPayload($data);

                try {
                    $shop = app(GameApiService::class)->createBetShop($payload, $data['idempotency_key']);
                } catch (GameApiException $e) {
                    static::recordBetShopWrite('BetShop', 0, 'create_failed', [...$payload, 'idempotency_key' => $data['idempotency_key']], $e->statusCode, static::betShopErrorResponse($e));

                    $this->handleBetShopWriteFailure($e, $action, 'Could not create the shop', self::FORM_FIELDS);

                    return;
                }

                static::recordBetShopWrite('BetShop', (int) ($shop['id'] ?? 0), 'created', [...$payload, 'idempotency_key' => $data['idempotency_key']], 201, $shop);

                Notification::make()
                    ->title('Shop '.($shop['shop_no'] ?? $payload['shop_no']).' created')
                    ->body('The owner buys float by paying the paybill with bill ref '.($shop['float_bill_ref'] ?? 'SHOP-'.$payload['shop_no']).'.')
                    ->success()
                    ->send();

                if (isset($shop['id'])) {
                    $this->redirect(BetShopDetailPage::getUrl(['shop' => (int) $shop['id']]), navigate: true);

                    return;
                }

                $this->afterBetShopWrite();
            });
    }

    protected function afterBetShopWrite(): void
    {
        $this->flushCachedTableRecords();
    }
}
