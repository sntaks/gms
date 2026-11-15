<?php

namespace App\Filament\Pages;

use App\Exceptions\GameApiException;
use App\Models\AuditLog;
use App\Services\GameApiService;
use App\Support\ApiAuditLog;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Signup bonus promo codes, read from KadiApi's `/promo-codes` endpoint. Anyone
 * who can view customers sees the list; creating and deactivating codes needs
 * `promo-codes.manage`. Each write carries an Idempotency-Key generated when the
 * modal opens (fresh after a 422 or 409) and is written to the audit log.
 * Finance users are warned when the house wallet, which pays the bonuses, is
 * running low while codes are active.
 */
class PromoCodesPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const STATUSES = [
        'active' => 'Active',
        'expired' => 'Expired',
        'deactivated' => 'Deactivated',
    ];

    /**
     * Below this house wallet balance (KES) finance users are warned while codes are active.
     */
    public const HOUSE_WALLET_WARNING_BELOW = 1000;

    public const DEACTIVATE_WARNING = 'The code stops working at once. Players who signed up with this code but haven\'t verified yet will not get the bonus. The same happens when a code expires.';

    /**
     * Form fields KadiApi can return 422 errors for.
     *
     * @var list<string>
     */
    protected const CREATE_FIELDS = ['code', 'expires_at', 'max_redemptions', 'note'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Promo Codes';

    protected static string|UnitEnum|null $navigationGroup = '🎟️ Promotions';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'promo-codes';

    protected ?string $heading = 'Promo Codes';

    protected ?string $subheading = 'A code only gives the sign-up bonus while the bonus is switched on in the Kadi API (PROMOTIONS_SIGNUP_BONUS_ENABLED); it is switched there, not here.';

    protected string $view = 'filament.pages.promo-codes-page';

    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public bool $apiError = false;

    /**
     * Promo codes are visible to whoever can view customers.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('accounts.view') ?? false;
    }

    public static function canManagePromoCodes(): bool
    {
        return auth()->user()?->hasPermissionTo('promo-codes.manage') ?? false;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'expired' => 'gray',
            'deactivated' => 'danger',
            default => 'gray',
        };
    }

    /**
     * "96 / 500", or "96 / ∞" for an unlimited code.
     *
     * @param  array<string, mixed>  $promoCode
     */
    public static function redemptionsLabel(array $promoCode): string
    {
        $max = $promoCode['max_redemptions'] ?? null;

        return number_format((int) ($promoCode['redemptions'] ?? 0)).' / '.($max === null ? '∞' : number_format((int) $max));
    }

    /**
     * The GMS user who created or deactivated each code, keyed by code id. KadiApi only
     * records the API key, so codes written elsewhere have no entry.
     *
     * @param  list<int|string>  $promoCodeIds
     * @return array<int, string>
     */
    public static function gmsUsers(array $promoCodeIds, string $event): array
    {
        if ($promoCodeIds === []) {
            return [];
        }

        return AuditLog::query()
            ->with('user:id,name')
            ->where('auditable_type', 'KadiApi\\PromoCode')
            ->where('event', $event)
            ->whereIn('auditable_id', array_map('intval', $promoCodeIds))
            ->latest('id')
            ->get()
            ->unique('auditable_id')
            ->filter(fn (AuditLog $log): bool => filled($log->user?->name))
            ->mapWithKeys(fn (AuditLog $log): array => [(int) $log->auditable_id => $log->user->name])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bonusCosts')
                ->label('Bonus costs')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->visible(fn (): bool => SignupBonusesReport::canAccess())
                ->url(fn (): string => SignupBonusesReport::getUrl()),
            $this->createPromoCodeAction(),
        ];
    }

    /**
     * A warning for finance users when the house wallet is below
     * {@see HOUSE_WALLET_WARNING_BELOW} while codes are active: KadiApi stops granting
     * bonuses once it cannot cover one. Both lookups are cached for a minute.
     */
    public function houseWalletWarning(): ?string
    {
        if (! SignupBonusesReport::canAccess()) {
            return null;
        }

        try {
            $gameApi = app(GameApiService::class);
            $activeCodes = $gameApi->countActivePromoCodes();

            if ($activeCodes === 0) {
                return null;
            }

            $houseWallet = (float) ($gameApi->financeReport('balance-sheet', cacheSeconds: 60)['house_wallet'] ?? 0);
        } catch (\Throwable $e) {
            Log::warning('House wallet check failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($houseWallet >= self::HOUSE_WALLET_WARNING_BELOW) {
            return null;
        }

        return 'The house wallet has '.Format::money($houseWallet).' and active promo codes: '.number_format($activeCodes)
            .'. Each bonus costs KES 21.05; once the house wallet cannot cover one, KadiApi stops granting bonuses.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport(
                $this->fetchPromoCodes([
                    'status' => $filters['status']['value'] ?? null,
                    'page' => max(1, (int) $page),
                    'per_page' => min(200, max(1, (int) $recordsPerPage)),
                ]),
            ))
            ->columns([
                TextColumn::make('code')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state))
                    ->description(fn (array $record): ?string => filled($record['deactivated_at'] ?? null)
                        ? Format::dateTime($record['deactivated_at']).' by '.$record['deactivated_by_name']
                        : null),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
                TextColumn::make('signups')
                    ->alignEnd()
                    ->numeric(),
                TextColumn::make('redemptions')
                    ->label('Bonuses paid / max')
                    ->alignEnd()
                    ->state(fn (array $record): string => static::redemptionsLabel($record)),
                TextColumn::make('remaining')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state))
                    ->placeholder('Unlimited'),
                TextColumn::make('note')
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('created_by_name')
                    ->label('Created by')
                    ->description(fn (array $record): string => Format::dateTime($record['created_at'] ?? null)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::STATUSES)
                    ->default('active'),
            ])
            ->recordActions([
                $this->deactivatePromoCodeAction(),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('heroicon-o-ticket')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Promo codes unavailable' : 'No promo codes found')
            ->emptyStateDescription(fn (): string => $this->apiError
                ? 'KadiApi could not be reached. Refresh the page to try again.'
                : 'Try a different status, or create a code.')
            ->striped();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function fetchPromoCodes(array $query): array
    {
        try {
            $data = app(GameApiService::class)->listPromoCodes($query);
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Promo codes list failed', ['error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->values();

        $ids = $items->pluck('id')->all();
        $creators = static::gmsUsers($ids, 'created');
        $deactivators = static::gmsUsers($ids, 'deactivated');

        $data['items'] = $items
            ->map(fn (array $row): array => [
                ...$row,
                ArrayRecord::getKeyName() => (string) $row['id'],
                'created_by_name' => $creators[(int) $row['id']] ?? UnmatchedDepositsPage::resolvedBy($row['created_by'] ?? null) ?? '—',
                'deactivated_by_name' => $deactivators[(int) $row['id']] ?? UnmatchedDepositsPage::resolvedBy($row['deactivated_by'] ?? null) ?? '—',
            ])
            ->all();

        return $data;
    }

    protected function createPromoCodeAction(): Action
    {
        return Action::make('createPromoCode')
            ->label('New promo code')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => static::canManagePromoCodes())
            ->modalHeading('New promo code')
            ->modalDescription('Codes can\'t be edited once created. To extend a campaign, create a new code and deactivate the old one if needed.')
            ->modalSubmitActionLabel('Create code')
            ->schema([
                Hidden::make('idempotency_key')
                    ->default(fn (): string => Str::uuid()->toString()),
                TextInput::make('code')
                    ->required()
                    ->minLength(4)
                    ->maxLength(30)
                    ->regex('/^[A-Za-z0-9_-]+$/')
                    ->validationMessages(['regex' => 'Use only letters, digits, - and _.'])
                    ->helperText('4 to 30 letters, digits, - or _. Saved in upper case.')
                    ->placeholder('LAUNCH-OCT')
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                DateTimePicker::make('expires_at')
                    ->label('Expires at (Nairobi time)')
                    ->required()
                    ->seconds(false)
                    ->minDate(fn (): CarbonInterface => now())
                    ->helperText('Players must both sign up and verify their email and phone before this time to get the bonus.'),
                TextInput::make('max_redemptions')
                    ->label('Maximum bonuses')
                    ->integer()
                    ->minValue(1)
                    ->helperText('Leave empty for unlimited.'),
                TextInput::make('note')
                    ->maxLength(255),
            ])
            ->action(function (array $data, Action $action): void {
                $payload = [
                    'code' => $data['code'],
                    'expires_at' => Carbon::parse($data['expires_at'])->format('Y-m-d H:i'),
                    'max_redemptions' => filled($data['max_redemptions'] ?? null) ? (int) $data['max_redemptions'] : null,
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                ];

                try {
                    $promoCode = app(GameApiService::class)->createPromoCode($payload, $data['idempotency_key']);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('PromoCode', 0, 'create_failed', [...$payload, 'idempotency_key' => $data['idempotency_key']], $e->statusCode, [
                        'message' => $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage(),
                        'errors' => $e->errors,
                    ]);

                    $this->handleCreateFailure($e, $action);

                    return;
                }

                ApiAuditLog::record('PromoCode', (int) ($promoCode['id'] ?? 0), 'created', [...$payload, 'idempotency_key' => $data['idempotency_key']], 201, $promoCode);

                Notification::make()
                    ->title('Promo code '.($promoCode['code'] ?? $payload['code']).' created')
                    ->body('Expires '.Format::dateTime($promoCode['expires_at'] ?? null).'.')
                    ->success()
                    ->send();

                $this->resetTable();
            });
    }

    /**
     * A 422 puts KadiApi's errors on the form fields. A 409 means the Idempotency-Key was
     * reused with a different form. Both get a fresh key, because the next submit is a new
     * request. Every failure keeps the modal open so nothing typed is lost; after a rate
     * limit or outage the retry reuses the same key.
     *
     * @throws ValidationException
     */
    protected function handleCreateFailure(GameApiException $e, Action $action): void
    {
        $message = $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();

        if (in_array($e->statusCode, [409, 422], true)) {
            $this->mountedActions[$action->getNestingIndex()]['data']['idempotency_key'] = Str::uuid()->toString();
        }

        $fieldErrors = $e->statusCode === 422 ? Arr::only($e->errors, self::CREATE_FIELDS) : [];

        if ($fieldErrors !== []) {
            throw ValidationException::withMessages(collect($fieldErrors)
                ->mapWithKeys(fn (array|string $messages, string $field): array => [
                    "mountedActions.{$action->getNestingIndex()}.data.{$field}" => Arr::wrap($messages),
                ])
                ->all());
        }

        Notification::make()
            ->title('Could not create the promo code')
            ->body($message)
            ->danger()
            ->send();

        $action->halt();
    }

    protected function deactivatePromoCodeAction(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canManagePromoCodes() && ($record['status'] ?? null) === 'active')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => 'Deactivate '.($record['code'] ?? 'promo code').'?')
            ->modalDescription(self::DEACTIVATE_WARNING)
            ->modalSubmitActionLabel('Deactivate code')
            ->schema([
                Hidden::make('idempotency_key')
                    ->default(fn (): string => Str::uuid()->toString()),
            ])
            ->action(function (array $data, array $record, Action $action): void {
                $promoCodeId = (int) $record['id'];
                $payload = ['code' => $record['code'] ?? null, 'idempotency_key' => $data['idempotency_key']];

                try {
                    $promoCode = app(GameApiService::class)->deactivatePromoCode($promoCodeId, $data['idempotency_key']);
                } catch (GameApiException $e) {
                    $message = $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();

                    ApiAuditLog::record('PromoCode', $promoCodeId, 'deactivate_failed', $payload, $e->statusCode, ['message' => $message]);

                    // Already deactivated or gone: nothing to retry, so close and refresh.
                    if (in_array($e->statusCode, [404, 409], true)) {
                        Notification::make()
                            ->title($e->statusCode === 404 ? 'Promo code not found' : 'Promo code not deactivated')
                            ->body($message)
                            ->warning()
                            ->send();

                        $this->resetTable();

                        return;
                    }

                    Notification::make()
                        ->title('Could not deactivate the promo code')
                        ->body($message)
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                ApiAuditLog::record('PromoCode', $promoCodeId, 'deactivated', $payload, 200, $promoCode);

                Notification::make()
                    ->title('Promo code '.($promoCode['code'] ?? $record['code'] ?? '').' deactivated')
                    ->success()
                    ->send();

                $this->resetTable();
            });
    }
}
