<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesOneShotApiWrites;
use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * The Kadi API's products (`GET /products`): what players stake on within each
 * game (one per slot title or scratch card), with its stake and payout limits
 * and winnings tax rate. Admins (`games.manage`) change them.
 */
class GameProductsPage extends Page implements HasTable
{
    use HandlesOneShotApiWrites;
    use InteractsWithTable;

    /**
     * api Product::STATUSES.
     *
     * @var array<string, string>
     */
    public const STATUSES = ['active' => 'Active', 'suspended' => 'Suspended', 'retired' => 'Retired'];

    public const LIMITS_HELP = 'Leave a limit empty to remove it. The Kadi game (kadi, kadi_tournament, kadi_jackpot) does not read these limits or the tax rate.';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Products';

    protected static string|UnitEnum|null $navigationGroup = '🎰 Games';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'games/products';

    protected ?string $heading = 'Products';

    protected ?string $subheading = 'Stake and payout limits and the winnings tax rate per product, in KES.';

    protected string $view = 'filament.pages.games-page';

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return GamesPage::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->fetchProducts())
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (array $record): string => $record['code']),
                TextColumn::make('game'),
                TextColumn::make('revenue_model')->label('Model')->formatStateUsing(fn (?string $state): string => $state === 'house_banked' ? 'House-banked' : ucfirst((string) $state)),
                TextColumn::make('status')->badge()
                    ->color(fn (?string $state): string => GamesPage::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('min_stake')->label('Min stake')->alignEnd()->formatStateUsing(fn ($state): string => Format::money($state))->placeholder('No limit'),
                TextColumn::make('max_stake')->label('Max stake')->alignEnd()->formatStateUsing(fn ($state): string => Format::money($state))->placeholder('No limit'),
                TextColumn::make('max_payout')->label('Max payout')->alignEnd()->formatStateUsing(fn ($state): string => Format::money($state))->placeholder('No limit'),
                TextColumn::make('winnings_tax_rate')->label('Winnings tax')->alignEnd()->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format(((float) $state) * 100, 2), '0'), '.').'%'),
            ])
            ->filters([
                SelectFilter::make('game')->options(fn (): array => static::gameOptions()),
            ])
            ->recordActions([$this->editProductAction()])
            ->paginated(false)
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Products unavailable' : 'No products');
    }

    /**
     * @return array<string, string> Game code => name.
     */
    public static function gameOptions(): array
    {
        try {
            return collect(app(GameApiService::class)->listGames())->pluck('name', 'code')->all();
        } catch (GameApiException) {
            return [];
        }
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    protected function fetchProducts(): Collection
    {
        try {
            $products = app(GameApiService::class)->listProducts($this->tableFilters['game']['value'] ?? null);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('Products list failed', ['error' => $e->getMessage()]);
            $this->apiError = true;

            return collect();
        }

        return collect($products)
            ->filter(fn ($product): bool => is_array($product) && isset($product['code']))
            ->mapWithKeys(fn (array $product): array => [$product['code'] => [...$product, ArrayRecord::getKeyName() => $product['code']]]);
    }

    /**
     * The form's values as the API's: empty limits are sent as null (no limit); the tax rate as a fraction.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function productPayload(array $data): array
    {
        $limit = fn (string $key): ?float => filled($data[$key] ?? null) ? round((float) $data[$key], 2) : null;

        return [
            'name' => trim((string) $data['name']),
            'status' => $data['status'],
            'min_stake' => $limit('min_stake'),
            'max_stake' => $limit('max_stake'),
            'max_payout' => $limit('max_payout'),
            'winnings_tax_rate' => round(((float) $data['winnings_tax_percent']) / 100, 4),
        ];
    }

    protected function editProductAction(): Action
    {
        return Action::make('editProduct')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => GamesPage::canManageGames())
            ->modalHeading(fn (array $record): string => 'Edit '.$record['name'])
            ->modalDescription(self::LIMITS_HELP)
            ->fillForm(fn (array $record): array => [
                'name' => $record['name'] ?? null,
                'status' => $record['status'] ?? null,
                'min_stake' => $record['min_stake'] ?? null,
                'max_stake' => $record['max_stake'] ?? null,
                'max_payout' => $record['max_payout'] ?? null,
                'winnings_tax_percent' => round(((float) ($record['winnings_tax_rate'] ?? 0)) * 100, 2),
            ])
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('status')->options(self::STATUSES)->required(),
                TextInput::make('min_stake')->label('Min stake (KES)')->numeric()->gt(0),
                TextInput::make('max_stake')->label('Max stake (KES)')->numeric()->gt(0),
                TextInput::make('max_payout')->label('Max payout (KES)')->numeric()->gt(0),
                TextInput::make('winnings_tax_percent')->label('Winnings tax (%)')->numeric()->minValue(0)->maxValue(100)->step(0.01)->required(),
            ])
            ->action(function (array $record, array $data, Action $action): void {
                abort_unless(GamesPage::canManageGames(), 403);

                $payload = static::productPayload($data);
                $result = $this->sendOneShotWrite($action, fn (): array => app(GameApiService::class)->updateProduct($record['code'], $payload), 'Product', 0, 'update:'.$record['code'], $payload, 'Could not save the product', [
                    'name' => 'name', 'status' => 'status', 'min_stake' => 'min_stake', 'max_stake' => 'max_stake', 'max_payout' => 'max_payout', 'winnings_tax_rate' => 'winnings_tax_percent',
                ]);

                if ($result !== null) {
                    Notification::make()->title($payload['name'].' saved')->success()->send();
                    $this->afterOneShotWrite();
                }
            });
    }

    protected function afterOneShotWrite(): void
    {
        $this->flushCachedTableRecords();
    }
}
