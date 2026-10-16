<?php

namespace App\Filament\Pages;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Every M-Pesa payment KadiApi received, read from the paginated
 * `/finance/deposits` report. Read-only: support (managers) and admins can
 * look deposits up, but nothing here changes one.
 */
class DepositsPage extends FinanceListReportPage
{
    /**
     * KadiApi's numeric deposit statuses, as the `status` filter sends them.
     */
    public const STATUSES = [
        0 => 'unmatched',
        1 => 'pending',
        2 => 'processed',
        4 => 'refunded',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Deposits';

    protected static ?string $cluster = null;

    protected static string|UnitEnum|null $navigationGroup = '📊 Finance';

    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'Deposits';

    #[Url]
    public string $period = 'today';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('deposits.view') ?? false;
    }

    protected function reportKey(): string
    {
        return 'deposits';
    }

    protected function exportKey(): ?string
    {
        return 'deposits';
    }

    protected function extraFilters(): array
    {
        return [
            'status' => $this->filterValue('status'),
            'kind' => $this->filterValue('kind'),
            'customer_id' => $this->filterValue('customer_id'),
        ];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $unmatched = $summary['by_status']['unmatched'] ?? [];
        $top = $summary['top_depositors'][0] ?? null;

        return [
            ['label' => 'Payments Received', 'value' => number_format((int) ($summary['payments'] ?? 0)), 'description' => 'In the selected period', 'icon' => 'heroicon-m-arrow-down-tray', 'color' => 'primary'],
            ['label' => 'Total Received', 'value' => Format::money($summary['amount'] ?? 0), 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
            ['label' => 'Unmatched Deposits', 'value' => Format::money($unmatched['amount'] ?? 0), 'description' => number_format((int) ($unmatched['payments'] ?? 0)).' payments not credited to a player', 'icon' => 'heroicon-m-exclamation-triangle', 'color' => ($unmatched['payments'] ?? 0) > 0 ? 'warning' : 'gray'],
            ['label' => 'Top Depositor', 'value' => $top ? Format::money($top['amount'] ?? 0) : '—', 'description' => $top['customer_name'] ?? 'No matched deposits', 'icon' => 'heroicon-m-trophy', 'color' => 'info'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'By kind',
                'headers' => ['Kind', 'Payments', 'Amount'],
                'rows' => collect($data['summary']['by_kind'] ?? [])
                    ->map(fn (array $row, string $kind): array => [str($kind)->replace('_', ' ')->title()->toString(), number_format((int) ($row['payments'] ?? 0)), Format::money($row['amount'] ?? 0)])
                    ->values()
                    ->all(),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('created_at')
                ->label('Date')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('trans_id')
                ->label('M-Pesa Ref')
                ->copyable(),
            TextColumn::make('customer_name')
                ->label('Player')
                ->weight('bold')
                ->placeholder('Unmatched')
                ->url(fn (array $record): ?string => ReferralWithdrawalsPage::customerUrl($record['customer_id'] ?? null)),
            TextColumn::make('msisdn')
                ->label('Payer')
                ->formatStateUsing(fn (?string $state): string => Format::payerPhone($state))
                ->tooltip(fn (array $record): ?string => Format::isHashedPhone($record['msisdn'] ?? null) ? $record['msisdn'] : null)
                ->color(fn (?string $state): ?string => Format::isHashedPhone($state) ? 'gray' : null)
                ->placeholder('—'),
            TextColumn::make('bill_ref_no')
                ->label('Account Ref')
                ->color('gray')
                ->size(TextSize::Small),
            TextColumn::make('kind')
                ->label('Kind')
                ->badge()
                ->color(fn (?string $state): string => $state === 'unmatched' ? 'warning' : 'info')
                ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->title()->toString()),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => static::statusColor($state))
                ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
            TextColumn::make('amount')
                ->label('Amount')
                ->weight('bold')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('status')->options([
                '0' => 'Unmatched',
                '1' => 'Pending',
                '2' => 'Processed',
                '4' => 'Refunded',
            ]),
            SelectFilter::make('kind')->options([
                'wallet_deposit' => 'Wallet deposit',
                'load' => 'Load',
                'gift' => 'Gift',
                'emoji' => 'Emoji',
                'unmatched' => 'Unmatched',
            ]),
            Filter::make('customer_id')
                ->schema([
                    Select::make('value')
                        ->label('Customer')
                        ->placeholder('Search by name, account or phone')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => static::searchCustomers($search))
                        ->getOptionLabelUsing(fn ($value): string => static::customerLabel((int) $value)),
                ])
                ->indicateUsing(fn (array $data): ?string => blank($data['value'] ?? null) ? null : 'Customer: '.static::customerLabel((int) $data['value'])),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->records(function (): LengthAwarePaginator {
                $paginator = ApiTablePaginator::fromReport($this->getReport());

                // Key rows by deposit id so the view action always opens the row that was clicked.
                return $paginator->setCollection($paginator->getCollection()->keyBy(fn (array $row): string => (string) ($row['id'] ?? '')));
            })
            ->recordActions([$this->viewDepositAction()]);
    }

    /**
     * A slide-over with the deposit's full detail from `GET /deposits/{id}`:
     * the payer, the 5% excise split and, once resolved, how it was resolved.
     */
    protected function viewDepositAction(): Action
    {
        return Action::make('view')
            ->iconButton()
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->tooltip('View deposit')
            ->slideOver()
            ->modalHeading(fn (array $record): string => 'Deposit '.($record['trans_id'] ?? '#'.$record['id']))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->fillForm(function (array $record, Action $action): array {
                try {
                    return static::depositDetail(app(GameApiService::class)->getDeposit((int) $record['id']));
                } catch (GameApiException $e) {
                    Log::warning('Deposit detail failed', ['deposit_id' => $record['id'], 'error' => $e->getMessage()]);

                    Notification::make()
                        ->title('Could not load the deposit')
                        ->body($e->apiMessage !== '' ? $e->apiMessage : 'The wallet API could not be reached. Try again shortly.')
                        ->danger()
                        ->send();

                    $action->cancel();

                    return [];
                }
            })
            ->schema([
                Section::make('Payment')
                    ->columns(2)
                    ->schema([
                        static::entry('trans_id')->label('M-Pesa Ref')->copyable(),
                        static::entry('trans_time')->label('Received'),
                        static::entry('amount')->label('Amount'),
                        static::entry('status')->badge()->color(fn (?string $state): string => static::statusColor(strtolower((string) $state))),
                        static::entry('payer')->label('Payer name'),
                        static::entry('short_code')->label('Paybill'),
                        static::entry('bill_ref_no')->label('Account Ref'),
                        static::entry('customer')->label('Credited to')
                            ->url(fn ($get): ?string => ReferralWithdrawalsPage::customerUrl($get('customer_id')))
                            ->hidden(fn ($state): bool => blank($state)),
                    ]),
                Section::make('Excise duty')
                    ->description('The wallet keeps the net amount after 5% excise duty.')
                    ->columns(2)
                    ->schema([
                        static::entry('excise_amount')->label('Excise duty'),
                        static::entry('net_amount')->label('Credited to wallet'),
                    ]),
                Section::make('Resolution')
                    ->columns(2)
                    ->visible(fn ($get): bool => filled($get('resolution_action')))
                    ->schema([
                        static::entry('resolution_action')->label('Action')->badge(),
                        static::entry('resolution_reference')->label('M-Pesa reversal ref')->hidden(fn ($state): bool => blank($state)),
                        static::entry('resolution_note')->label('Note')->columnSpanFull(),
                        static::entry('resolution_by')->label('Resolved by'),
                        static::entry('resolution_at')->label('Resolved at'),
                    ]),
            ]);
    }

    /**
     * An entry showing a value from the slide-over's data. Entries read a
     * record by default, and these rows are plain arrays filled by fillForm().
     */
    protected static function entry(string $name): TextEntry
    {
        return TextEntry::make($name)
            ->state(fn (TextEntry $component): mixed => data_get($component->getContainer()->getRawState(), $name))
            ->placeholder('—');
    }

    /**
     * Flatten KadiApi's deposit into display strings. The documented shape
     * (`trans_amount`, `customer` as a name) and the richer one (`amount`,
     * `customer` object, `resolution`) are both accepted.
     *
     * @param  array<string, mixed>  $deposit
     * @return array<string, string|null>
     */
    public static function depositDetail(array $deposit): array
    {
        $customer = $deposit['customer'] ?? null;
        $resolution = is_array($deposit['resolution'] ?? null) ? $deposit['resolution'] : [];
        $status = $deposit['status'] ?? null;
        $amount = $deposit['amount'] ?? $deposit['trans_amount'] ?? 0;

        // A resolved unmatched deposit names its customer in `resolution.customer_name`.
        $creditedTo = match (true) {
            is_array($customer) => trim(($customer['name'] ?? 'Customer').(isset($customer['account_no']) ? ' · '.$customer['account_no'] : '')),
            filled($customer) => $customer,
            default => $resolution['customer_name'] ?? null,
        };

        return [
            'trans_id' => $deposit['trans_id'] ?? null,
            'trans_time' => Format::dateTime($deposit['trans_time'] ?? $deposit['created_at'] ?? null),
            'amount' => Format::money($amount),
            'status' => ucfirst(is_numeric($status) ? (self::STATUSES[(int) $status] ?? (string) $status) : (string) $status),
            'payer' => $deposit['name'] ?? null,
            'short_code' => isset($deposit['short_code']) ? (string) $deposit['short_code'] : null,
            'bill_ref_no' => $deposit['bill_ref_no'] ?? null,
            'customer_id' => is_array($customer) ? ($customer['id'] ?? null) : ($deposit['customer_id'] ?? $resolution['customer_id'] ?? null),
            'customer' => $creditedTo,
            // Both are null when no excise was charged, so the wallet kept the full amount.
            'excise_amount' => isset($deposit['excise_amount']) ? Format::money($deposit['excise_amount']) : 'None charged',
            'net_amount' => Format::money($deposit['net_amount'] ?? $amount),
            'resolution_action' => isset($resolution['action']) ? ucfirst((string) $resolution['action']) : null,
            'resolution_reference' => $resolution['mpesa_reference'] ?? null,
            'resolution_note' => $resolution['note'] ?? null,
            'resolution_by' => UnmatchedDepositsPage::resolvedBy($resolution['resolved_by'] ?? null),
            'resolution_at' => isset($resolution['resolved_at']) ? Format::dateTime($resolution['resolved_at']) : null,
        ];
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'processed' => 'success',
            'pending' => 'warning',
            'unmatched' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Customers matching a name, account number or phone, for the customer filter.
     *
     * @return array<int, string>
     */
    public static function searchCustomers(string $search): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }

        try {
            $result = app(GameApiService::class)->searchCustomers(trim($search));
        } catch (GameApiException $e) {
            Log::warning('Customer search failed', ['error' => $e->getMessage()]);

            return [];
        }

        return collect($result['data'] ?? $result)
            ->filter(fn ($customer): bool => is_array($customer) && isset($customer['id']))
            ->take(50)
            ->mapWithKeys(fn (array $customer): array => [(int) $customer['id'] => trim(($customer['name'] ?? 'Customer').' · '.($customer['account_no'] ?? '#'.$customer['id']))])
            ->all();
    }

    /**
     * The chosen customer's name for the filter, cached so re-renders don't call the API.
     */
    public static function customerLabel(int $customerId): string
    {
        $key = "deposits.customer-label.{$customerId}";

        if (($label = Cache::get($key)) !== null) {
            return $label;
        }

        try {
            $customer = app(GameApiService::class)->getCustomer($customerId);
        } catch (GameApiException) {
            return 'Customer #'.$customerId;
        }

        $label = trim(($customer['name'] ?? 'Customer').' · '.($customer['account_no'] ?? '#'.$customerId));
        Cache::put($key, $label, now()->addMinutes(10));

        return $label;
    }

    protected function emptyHeading(): string
    {
        return 'No deposits found';
    }
}
