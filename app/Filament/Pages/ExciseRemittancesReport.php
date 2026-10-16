<?php

namespace App\Filament\Pages;

use App\Exceptions\GameApiException;
use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Excise duty payments made to KRA. Recording one attaches every unremitted
 * charge in its period; voiding one detaches them so they are owed again.
 * Both writes need the `excise-duty.remit` permission and carry an
 * Idempotency-Key generated when the modal opens, so a retry replays.
 */
class ExciseRemittancesReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'KRA Remittances';

    protected static ?int $navigationSort = 18;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::TAX;

    protected ?string $heading = 'KRA Remittances';

    /**
     * A month's duty is paid in the following month, so a single-month default would hide it.
     */
    #[Url]
    public string $period = 'this_year';

    public static function canRemit(): bool
    {
        $user = auth()->user();

        return ($user?->isAdmin() ?? false) && $user->hasPermissionTo('excise-duty.remit');
    }

    protected function reportKey(): string
    {
        return 'excise-duty/remittances';
    }

    protected function exportKey(): ?string
    {
        return 'excise-duty-remittances';
    }

    protected function extraFilters(): array
    {
        return ['status' => $this->filterValue('status') ?? 'active'];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $active = $summary['active'] ?? [];
        $voided = $summary['voided'] ?? [];

        return [
            ['label' => 'Paid to KRA', 'value' => Format::money($active['amount_paid'] ?? 0), 'description' => number_format((int) ($active['remittances'] ?? 0)).' remittances · due '.Format::money($active['amount_due'] ?? 0), 'icon' => 'heroicon-m-check-badge', 'color' => 'success'],
            ['label' => 'Still Payable', 'value' => Format::money($summary['payable'] ?? 0), 'description' => 'Unremitted duty across all time', 'icon' => 'heroicon-m-building-library', 'color' => ($summary['payable'] ?? 0) > 0 ? 'warning' : 'success'],
            ['label' => 'Voided', 'value' => number_format((int) ($voided['remittances'] ?? 0)), 'description' => Format::money($voided['amount_paid'] ?? 0).' reversed', 'icon' => 'heroicon-m-x-circle', 'color' => 'gray'],
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            // Key rows by remittance id so Void can never land on a different row if the list shifts.
            ->records(function (): LengthAwarePaginator {
                $paginator = ApiTablePaginator::fromReport($this->getReport());

                return $paginator->setCollection($paginator->getCollection()
                    ->filter(fn (array $row): bool => isset($row['id']))
                    ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => ['key' => (string) $row['id'], ...$row]]));
            })
            ->recordActions([$this->voidAction()]);
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('period_start')
                ->label('Period')
                ->weight('bold')
                ->formatStateUsing(fn ($state, array $record): string => Format::date($state).' – '.Format::date($record['period_end'] ?? null)),
            TextColumn::make('kra_reference')
                ->label('KRA Reference')
                ->fontFamily('mono')
                ->copyable(),
            TextColumn::make('paid_at')
                ->label('Paid')
                ->formatStateUsing(fn ($state): string => Format::date($state)),
            TextColumn::make('charges')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => number_format((int) $state)),
            TextColumn::make('amount_due')
                ->label('Due')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('amount_paid')
                ->label('Paid')
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('difference')
                ->alignEnd()
                ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : ((float) $state > 0 ? 'warning' : null))
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => $state === 'active' ? 'success' : 'gray')
                ->description(fn (array $record): ?string => ($record['status'] ?? null) === 'voided' ? Str::limit((string) ($record['void_reason'] ?? ''), 40) : null)
                ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
            TextColumn::make('recorded_by')
                ->label('Recorded By')
                ->color('gray')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('status')
                ->options(['active' => 'Active', 'voided' => 'Voided', 'all' => 'All'])
                ->default('active'),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No remittances recorded';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            $this->recordRemittanceAction(),
        ];
    }

    protected function recordRemittanceAction(): Action
    {
        return Action::make('recordRemittance')
            ->label('Record remittance')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->visible(fn (): bool => static::canRemit())
            ->modalHeading('Record a KRA remittance')
            ->modalDescription('Every unremitted excise charge in the period is attached to this payment and can no longer be refunded to the player.')
            ->modalSubmitActionLabel('Record remittance')
            ->schema([
                Hidden::make('idempotency_key')
                    ->default(fn (): string => Str::uuid()->toString()),
                Select::make('month')
                    ->label('Return')
                    ->helperText('Pick an outstanding month to fill in the period and amount.')
                    ->options(fn (): array => $this->outstandingReturnOptions())
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $return = $this->outstandingReturns()[$state] ?? null;

                        if ($return === null) {
                            return;
                        }

                        $set('period_start', $return['period_start'] ?? null);
                        $set('period_end', $return['period_end'] ?? null);
                        $set('amount_paid', $return['outstanding'] ?? null);
                    })
                    ->dehydrated(false),
                DatePicker::make('period_start')
                    ->label('Period start')
                    ->required()
                    ->maxDate(today()),
                DatePicker::make('period_end')
                    ->label('Period end')
                    ->required()
                    ->afterOrEqual('period_start')
                    ->maxDate(today()),
                TextInput::make('amount_paid')
                    ->label('Amount paid')
                    ->prefix('KES')
                    ->numeric()
                    ->required()
                    ->minValue(0.01),
                TextInput::make('kra_reference')
                    ->label('KRA reference')
                    ->required()
                    ->maxLength(100),
                DatePicker::make('paid_at')
                    ->label('Paid on')
                    ->default(fn (): string => today()->toDateString())
                    ->maxDate(today()),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $remittance = app(GameApiService::class)->recordExciseRemittance($data, $data['idempotency_key']);
                } catch (GameApiException $e) {
                    $this->handleWriteFailure($e, $action, 'Could not record the remittance');

                    return;
                }

                Notification::make()
                    ->title('Remittance recorded')
                    ->body(number_format((int) ($remittance['charges'] ?? 0)).' charges attached · due '.Format::money($remittance['amount_due'] ?? 0)
                        .' · paid '.Format::money($remittance['amount_paid'] ?? $data['amount_paid']).'.')
                    ->success()
                    ->send();

                $this->refreshReport();
            });
    }

    protected function voidAction(): Action
    {
        return Action::make('void')
            ->label('Void')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canRemit() && ($record['status'] ?? null) === 'active')
            ->requiresConfirmation()
            ->modalHeading('Void remittance')
            ->modalDescription(fn (array $record): string => 'The '.number_format((int) ($record['charges'] ?? 0)).' charges on '.($record['kra_reference'] ?? 'this remittance')
                .' are detached and counted as owed to KRA again. Only void a payment that was recorded by mistake.')
            ->modalSubmitActionLabel('Void remittance')
            ->schema([
                Hidden::make('idempotency_key')
                    ->default(fn (): string => Str::uuid()->toString()),
                Textarea::make('reason')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255)
                    ->rows(3),
            ])
            ->action(function (array $data, array $record, Action $action): void {
                try {
                    app(GameApiService::class)->voidExciseRemittance((int) $record['id'], trim($data['reason']), $data['idempotency_key']);
                } catch (GameApiException $e) {
                    $this->handleWriteFailure($e, $action, 'Could not void the remittance');

                    return;
                }

                Notification::make()
                    ->title('Remittance voided')
                    ->body('Its charges are owed to KRA again.')
                    ->success()
                    ->send();

                $this->refreshReport();
            });
    }

    /**
     * Months with excise duty still owed, from the last year of returns, keyed by period.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function outstandingReturns(): array
    {
        try {
            $returns = app(GameApiService::class)->financeReport('excise-duty/returns', [
                'from' => today()->subMonths(11)->startOfMonth()->toDateString(),
                'to' => today()->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Excise returns lookup failed', ['error' => $e->getMessage()]);

            return [];
        }

        return collect($returns['items'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && (float) ($item['outstanding'] ?? 0) > 0 && filled($item['period_start'] ?? null))
            ->keyBy(fn (array $item): string => (string) ($item['period'] ?? $item['period_start']))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function outstandingReturnOptions(): array
    {
        return collect($this->outstandingReturns())
            ->map(fn (array $item): string => Carbon::parse($item['period_start'])->format('M Y')
                .' — '.Format::money($item['outstanding']).' outstanding'
                .(($item['overdue'] ?? false) ? ' (overdue)' : ' · due '.Format::date($item['due_date'] ?? null)))
            ->all();
    }

    /**
     * Rate limits and outages keep the modal open so a retry reuses the same Idempotency-Key;
     * a 409 means the remittance changed under us, so the list is refreshed.
     */
    protected function handleWriteFailure(GameApiException $e, Action $action, string $title): void
    {
        $message = $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();

        if ($e->statusCode === 409) {
            Notification::make()->title('Remittance already changed')->body($message)->warning()->send();

            app(GameApiService::class)->forgetFinanceReports();
            $this->refreshReport();

            return;
        }

        Notification::make()->title($title)->body($message)->danger()->send();

        if ($e->statusCode === 0 || $e->statusCode === 429 || $e->statusCode >= 500) {
            $action->halt();
        }
    }

    protected function refreshReport(): void
    {
        $this->cachedReport = null;
        $this->flushCachedTableRecords();
    }
}
