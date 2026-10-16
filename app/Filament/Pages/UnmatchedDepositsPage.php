<?php

namespace App\Filament\Pages;

use App\Concerns\CreditsDepositsToShopFloat;
use App\Concerns\HandlesBetShopWrites;
use App\Concerns\ResolvesUnmatchedDeposits;
use App\Filament\Widgets\UnmatchedDepositsWidget;
use App\Models\AuditLog;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\DepositSuggestion;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The unmatched-deposit work queue from KadiApi's `GET /deposits/unmatched`:
 * M-Pesa payments whose bill ref matched no customer, so nobody was credited.
 * Unmatched deposits are listed oldest first with KadiApi's suggested
 * customers; the Assigned, Refunded and Shop float tabs show how each was
 * resolved. Payments a bet shop owner made to buy float (bill ref
 * `SHOP-<shop_no>`) are badged and can be credited to the shop's float.
 */
class UnmatchedDepositsPage extends Page implements HasTable
{
    use CreditsDepositsToShopFloat;
    use HandlesBetShopWrites;
    use InteractsWithTable;
    use ResolvesUnmatchedDeposits;

    public const TABS = [
        'unmatched' => 'Unmatched',
        'assigned' => 'Assigned',
        'refunded' => 'Refunded',
        'shop_float' => 'Shop float',
    ];

    /**
     * Unmatched deposits older than this are highlighted: a player is waiting for their money.
     */
    public const OVERDUE_AFTER_HOURS = 24;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Unmatched Deposits';

    protected static string|UnitEnum|null $navigationGroup = '📊 Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'unmatched-deposits';

    protected ?string $heading = 'Unmatched Deposits';

    protected ?string $subheading = 'M-Pesa payments whose account number matched no customer, so nobody was credited.';

    protected string $view = 'filament.pages.unmatched-deposits-page';

    #[Url]
    public string $tab = 'unmatched';

    public bool $apiError = false;

    /**
     * Who in the GMS resolved each deposit on the current page, from the audit log.
     *
     * @var array<int, string>
     */
    protected array $gmsResolvers = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('deposits.view') ?? false;
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'unmatched';
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
        return [$this->matchByAccountAction()];
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [UnmatchedDepositsWidget::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage): LengthAwarePaginator {
                $paginator = ApiTablePaginator::fromReport($this->fetchDeposits((int) $page, (int) $recordsPerPage));
                $this->gmsResolvers = $this->tab === 'unmatched' ? [] : static::gmsResolvers($paginator->getCollection()->pluck('id')->all());

                // Key rows by deposit id so a row action always acts on the row that was clicked.
                return $paginator->setCollection($paginator->getCollection()->keyBy(fn (array $row): string => (string) ($row['id'] ?? '')));
            })
            ->columns([
                TextColumn::make('trans_time')
                    ->label('Received')
                    ->state(fn (array $record): ?string => $record['trans_time'] ?? $record['created_at'] ?? null)
                    ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
                TextColumn::make('trans_id')
                    ->label('M-Pesa ID')
                    ->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('amount')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('bill_ref_no')
                    ->label('Bill ref')
                    ->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('name')
                    ->label('Payer')
                    ->placeholder('—')
                    ->description(fn (array $record): ?string => filled($record['msisdn'] ?? null) ? Format::payerPhone($record['msisdn']) : null),

                // Unmatched tab
                TextColumn::make('bet_shop')
                    ->label('Shop float')
                    ->state(fn (array $record): ?string => is_array($record['bet_shop'] ?? null) ? 'Shop float · '.($record['bet_shop']['shop_no'] ?? '') : null)
                    ->badge()
                    ->color(fn (array $record): string => ($record['bet_shop']['status'] ?? null) === 'closed' ? 'gray' : 'info')
                    ->icon('heroicon-m-building-storefront')
                    ->tooltip(fn (array $record): ?string => is_array($record['bet_shop'] ?? null)
                        ? 'The owner of '.($record['bet_shop']['name'] ?? 'this shop').' buying float'.(($record['bet_shop']['status'] ?? null) === 'closed' ? ' (the shop is closed)' : '')
                        : null)
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->tab === 'unmatched'),
                TextColumn::make('top_suggestion')
                    ->label('Suggested customer')
                    ->state(fn (array $record): ?string => ($top = DepositSuggestion::top($record)) ? DepositSuggestion::customerLabel($top) : null)
                    ->placeholder('No suggestion')
                    ->description(fn (array $record): ?string => count($record['suggestions'] ?? []) > 1 ? '+'.(count($record['suggestions']) - 1).' more' : null)
                    ->url(fn (array $record): ?string => ReferralWithdrawalsPage::customerUrl(DepositSuggestion::top($record)['customer_id'] ?? null))
                    ->visible(fn (): bool => $this->tab === 'unmatched'),
                TextColumn::make('top_suggestion_match')
                    ->label('Why')
                    ->state(fn (array $record): array => array_column(($top = DepositSuggestion::top($record)) ? DepositSuggestion::badges($top) : [], 'label'))
                    ->badge()
                    ->color(fn (string $state, array $record): string => static::badgeColor($record, $state))
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->tab === 'unmatched'),
                TextColumn::make('age')
                    ->label('Age')
                    ->state(fn (array $record): ?string => static::receivedAt($record)?->diffForHumans(['parts' => 2, 'short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE]))
                    ->placeholder('—')
                    ->color(fn (array $record): ?string => static::isOverdue($record) ? 'danger' : null)
                    ->weight(fn (array $record): ?string => static::isOverdue($record) ? 'bold' : null)
                    ->icon(fn (array $record): ?string => static::isOverdue($record) ? 'heroicon-m-clock' : null)
                    ->iconColor('danger')
                    ->tooltip(fn (array $record): ?string => static::isOverdue($record) ? 'Waiting over '.self::OVERDUE_AFTER_HOURS.' hours' : null)
                    ->visible(fn (): bool => $this->tab === 'unmatched'),

                // Assigned and Refunded tabs
                TextColumn::make('resolution_action')
                    ->label('Resolution')
                    ->state(fn (array $record): ?string => $record['resolution']['action'] ?? null)
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'assigned' => 'success',
                        'shop_float' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => ucfirst(str_replace('_', ' ', (string) $state)))
                    ->visible(fn (): bool => $this->tab !== 'unmatched'),
                TextColumn::make('resolution_target')
                    ->label(fn (): string => $this->tab === 'refunded' ? 'Reversal ref' : 'Credited to')
                    ->state(fn (array $record): ?string => static::resolutionTarget($record))
                    ->placeholder('—')
                    ->url(fn (array $record): ?string => match ($this->tab) {
                        'assigned' => ReferralWithdrawalsPage::customerUrl($record['resolution']['customer_id'] ?? null),
                        'shop_float' => static::creditedShopUrl($record),
                        default => null,
                    })
                    ->visible(fn (): bool => $this->tab !== 'unmatched'),
                TextColumn::make('resolution_note')
                    ->label('Note')
                    ->state(fn (array $record): ?string => $record['resolution']['note'] ?? null)
                    ->limit(50)
                    ->tooltip(fn (array $record): ?string => $record['resolution']['note'] ?? null)
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->tab !== 'unmatched'),
                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->state(fn (array $record): ?string => isset($record['resolution']['resolved_at']) ? Format::dateTime($record['resolution']['resolved_at']) : null)
                    ->description(fn (array $record): ?string => isset($this->gmsResolvers[(int) ($record['id'] ?? 0)])
                        ? $this->gmsResolvers[(int) $record['id']].' (GMS)'
                        : static::resolvedBy($record['resolution']['resolved_by'] ?? null))
                    ->size(TextSize::Small)
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->tab !== 'unmatched'),
            ])
            ->recordActions([
                $this->suggestionsAction(),
                $this->assignDepositAction(),
                $this->refundDepositAction(),
                $this->creditShopFloatAction(),
                $this->voidShopFloatPurchaseAction(fn (array $record): ?array => static::resolvedFloatPurchase($record)),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon(fn (): string => $this->tab === 'unmatched' && ! $this->apiError ? 'heroicon-o-check-circle' : 'heroicon-o-document-magnifying-glass')
            ->emptyStateHeading(fn (): string => match (true) {
                $this->apiError => 'Unmatched deposits unavailable',
                $this->tab === 'unmatched' => 'No unmatched deposits',
                $this->tab === 'shop_float' => 'No deposits credited to shop floats yet',
                default => 'Nothing '.strtolower(self::TABS[$this->tab]).' yet',
            })
            ->emptyStateDescription(fn (): string => $this->apiError
                ? 'KadiApi could not be reached. Refresh the page to try again.'
                : ($this->tab === 'unmatched' ? 'Every payment has been credited to a customer.' : 'Resolved deposits appear here.'))
            ->striped();
    }

    /**
     * Every suggested customer for a deposit, with why KadiApi suggests them.
     */
    protected function suggestionsAction(): Action
    {
        return Action::make('suggestions')
            ->iconButton()
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('info')
            ->tooltip('Suggested customers')
            ->slideOver()
            ->modalHeading(fn (array $record): string => 'Suggested customers for '.Format::money($record['amount'] ?? 0))
            ->modalDescription(fn (array $record): string => 'Bill ref '.($record['bill_ref_no'] ?? '—').' · M-Pesa '.($record['trans_id'] ?? '—'))
            ->modalContent(fn (array $record) => view('filament.pages.partials.deposit-suggestions', [
                'suggestions' => collect($record['suggestions'] ?? [])
                    ->filter(fn ($suggestion): bool => is_array($suggestion))
                    ->map(fn (array $suggestion): array => [
                        'label' => DepositSuggestion::customerLabel($suggestion),
                        'phone' => $suggestion['phone_no'] ?? null,
                        'url' => ReferralWithdrawalsPage::customerUrl($suggestion['customer_id'] ?? null),
                        'badges' => DepositSuggestion::badges($suggestion),
                        'ambiguous' => ($suggestion['ambiguous'] ?? false) === true,
                    ])
                    ->values()
                    ->all(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->visible(fn (array $record): bool => $this->tab === 'unmatched' && DepositSuggestion::top($record) !== null);
    }

    protected function afterDepositResolved(): void
    {
        $this->flushCachedTableRecords();
        $this->dispatch(UnmatchedDepositsWidget::REFRESH_EVENT);
    }

    /**
     * The GMS user who assigned, refunded or matched each deposit. KadiApi only records the API key,
     * so deposits resolved elsewhere (the auto-matcher, another key) have no entry.
     *
     * @param  list<int|string>  $depositIds
     * @return array<int, string>
     */
    public static function gmsResolvers(array $depositIds): array
    {
        if ($depositIds === []) {
            return [];
        }

        return AuditLog::query()
            ->with('user:id,name')
            ->where('auditable_type', 'KadiApi\\Deposit')
            ->whereIn('event', ['assigned', 'refunded', 'matched', 'shop_float'])
            ->whereIn('auditable_id', array_map('intval', $depositIds))
            ->latest('id')
            ->get()
            ->unique('auditable_id')
            ->filter(fn (AuditLog $log): bool => filled($log->user?->name))
            ->mapWithKeys(fn (AuditLog $log): array => [(int) $log->auditable_id => $log->user->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchDeposits(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listUnmatchedDeposits([
                'status' => $this->tab,
                'page' => max(1, $page),
                'per_page' => min(200, max(1, $perPage)),
            ]);
            $this->apiError = false;

            return $data;
        } catch (\Throwable $e) {
            Log::warning('Unmatched deposits list failed', ['tab' => $this->tab, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $deposit
     */
    public static function receivedAt(array $deposit): ?Carbon
    {
        return Format::toCarbon($deposit['trans_time'] ?? $deposit['created_at'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $deposit
     */
    public static function isOverdue(array $deposit): bool
    {
        return static::receivedAt($deposit)?->lt(now()->subHours(self::OVERDUE_AFTER_HOURS)) ?? false;
    }

    /**
     * The customer credited, or the M-Pesa reversal reference for a refund.
     * KadiApi sends `customer_id` and `customer_name` (both null for refunds); the id is
     * shown until the name is available.
     *
     * @param  array<string, mixed>  $deposit
     */
    public static function resolutionTarget(array $deposit): ?string
    {
        $resolution = is_array($deposit['resolution'] ?? null) ? $deposit['resolution'] : [];

        if (($resolution['action'] ?? null) === 'refunded') {
            return $resolution['mpesa_reference'] ?? null;
        }

        if (($resolution['action'] ?? null) === 'shop_float') {
            return filled($resolution['shop_no'] ?? null) ? trim($resolution['shop_no'].' · '.($resolution['shop_name'] ?? '')) : null;
        }

        if (filled($resolution['customer_name'] ?? null)) {
            return $resolution['customer_name'];
        }

        return filled($resolution['customer_id'] ?? null) ? 'Customer #'.$resolution['customer_id'] : null;
    }

    /**
     * KadiApi's `resolved_by` names what resolved the deposit, not who:
     * `api_key:{id}` for a request with an API key (the GMS), or
     * `command:deposits:match-unmatched` for the account-number matcher.
     */
    public static function resolvedBy(?string $resolvedBy): ?string
    {
        if (blank($resolvedBy)) {
            return null;
        }

        return match (true) {
            str_starts_with($resolvedBy, 'api_key:') => 'GMS (API key #'.substr($resolvedBy, 8).')',
            $resolvedBy === 'command:deposits:match-unmatched' => 'Auto-match',
            str_starts_with($resolvedBy, 'command:') => 'Command '.substr($resolvedBy, 8),
            default => $resolvedBy,
        };
    }

    /**
     * @param  array<string, mixed>  $deposit
     */
    protected static function badgeColor(array $deposit, string $label): string
    {
        $top = DepositSuggestion::top($deposit);

        return collect($top ? DepositSuggestion::badges($top) : [])->firstWhere('label', $label)['color'] ?? 'gray';
    }
}
