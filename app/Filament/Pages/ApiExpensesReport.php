<?php

namespace App\Filament\Pages;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\ApiAuditLog;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The company's expenses in the Kadi API's finance ledger, which feed its
 * income statement. Expenses are recorded here (owner decision 2026-11-15; the
 * local Expenses resource is read-only history) and voided, never edited.
 * Both need finance-report access and `expenses.create`. Completed payouts are
 * recorded here automatically (RecordPayoutExpenseJob).
 */
class ApiExpensesReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'API Expenses';

    protected static ?int $navigationSort = 24;

    protected ?string $heading = 'Company Expenses';

    protected function reportKey(): string
    {
        return 'expenses';
    }

    protected function exportKey(): ?string
    {
        return 'expenses';
    }

    /**
     * api config finance.expense_categories.
     *
     * @var list<string>
     */
    public const CATEGORIES = ['mpesa_charges', 'hosting', 'sms', 'salaries', 'marketing', 'licences_and_fees', 'other'];

    public static function canRecord(): bool
    {
        $user = auth()->user();

        return ($user?->canViewFinanceReports() ?? false) && $user->hasPermissionTo('expenses.create');
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return collect(self::CATEGORIES)->mapWithKeys(fn (string $key): array => [$key => str($key)->replace('_', ' ')->title()->toString()])->all();
    }

    protected function getHeaderActions(): array
    {
        return [...parent::getHeaderActions(), $this->recordExpenseAction()];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            // Key rows by expense id so Void can never land on a different row if the list shifts.
            ->records(function (): LengthAwarePaginator {
                $paginator = ApiTablePaginator::fromReport($this->getReport());

                return $paginator->setCollection($paginator->getCollection()
                    ->filter(fn (array $row): bool => isset($row['id']))
                    ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => ['key' => (string) $row['id'], ...$row]]));
            })
            ->recordActions([$this->voidExpenseAction()]);
    }

    protected function recordExpenseAction(): Action
    {
        return Action::make('recordExpense')
            ->label('Record expense')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (): bool => static::canRecord())
            ->modalHeading('Record a company expense')
            ->modalDescription('It is counted in the income statement. An expense can be voided but never edited.')
            ->modalSubmitActionLabel('Record expense')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString(), 'expense_date' => today()->toDateString()])
            ->schema([
                Hidden::make('idempotency_key'),
                DatePicker::make('expense_date')->label('Date')->maxDate(today())->required(),
                Select::make('category')->options(static::categoryOptions())->required(),
                TextInput::make('amount')->label('Amount (KES)')->numeric()->gt(0)->maxValue(99_999_999.99)->required(),
                TextInput::make('description')->maxLength(255),
                TextInput::make('reference')->helperText('E.g. the M-Pesa receipt. It can be used only once.')->maxLength(100),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canRecord(), 403);

                $payload = Arr::only($data, ['expense_date', 'category', 'amount', 'description', 'reference']);

                try {
                    $expense = app(GameApiService::class)->recordExpense($payload, $data['idempotency_key']);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('FinanceExpense', 0, 'record', $payload, $e->statusCode, array_filter(['message' => $e->apiMessage ?: $e->getMessage(), 'errors' => $e->errors]));
                    $this->expenseWriteFailure($e, $action, 'Could not record the expense');

                    return;
                }

                ApiAuditLog::record('FinanceExpense', (int) ($expense['id'] ?? 0), 'record', $payload, 201, []);
                Notification::make()->title('Expense recorded')->body(Format::money($payload['amount']).' · '.(static::categoryOptions()[$payload['category']] ?? $payload['category']))->success()->send();
                $this->refreshAfterWrite();
            });
    }

    protected function voidExpenseAction(): Action
    {
        return Action::make('voidExpense')
            ->label('Void')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canRecord() && ($record['status'] ?? null) === 'active')
            ->modalHeading(fn (array $record): string => 'Void the expense of '.Format::money($record['amount'] ?? 0).'?')
            ->modalDescription('It is taken off the income statement but kept, marked voided.')
            ->modalSubmitActionLabel('Void expense')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                Textarea::make('reason')->required()->minLength(3)->maxLength(255)->rows(2),
            ])
            ->action(function (array $data, array $record, Action $action): void {
                abort_unless(static::canRecord(), 403);

                $id = (int) $record['id'];
                $payload = ['reason' => trim($data['reason'])];

                try {
                    app(GameApiService::class)->voidExpense($id, $payload['reason'], $data['idempotency_key']);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('FinanceExpense', $id, 'void', $payload, $e->statusCode, ['message' => $e->apiMessage ?: $e->getMessage()]);
                    $this->expenseWriteFailure($e, $action, 'Could not void the expense');

                    return;
                }

                ApiAuditLog::record('FinanceExpense', $id, 'void', $payload, 200, []);
                Notification::make()->title('Expense voided')->success()->send();
                $this->refreshAfterWrite();
            });
    }

    /**
     * These writes carry an Idempotency-Key, so a rate limit or outage keeps the modal open and a
     * resubmit replays the same attempt; a 422 lands on the form.
     *
     * @throws ValidationException
     */
    protected function expenseWriteFailure(GameApiException $e, Action $action, string $title): void
    {
        $message = $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();
        $index = $action->getNestingIndex();
        $fieldErrors = Arr::only($e->errors, ['expense_date', 'category', 'amount', 'description', 'reference', 'reason']);

        if ($e->statusCode === 422 && $fieldErrors !== []) {
            throw ValidationException::withMessages(collect($fieldErrors)
                ->mapWithKeys(fn (array|string $messages, string $field): array => ["mountedActions.{$index}.data.{$field}" => Arr::wrap($messages)])
                ->all());
        }

        $retryable = $e->statusCode === 0 || $e->statusCode === 429 || $e->statusCode >= 500;
        $notification = Notification::make()->title($title)->body($message);
        ($retryable ? $notification->warning() : $notification->danger())->send();

        if ($retryable) {
            $action->halt();
        }

        $this->refreshAfterWrite();
    }

    protected function refreshAfterWrite(): void
    {
        $this->cachedReport = null;
        $this->flushCachedTableRecords();
    }

    protected function extraFilters(): array
    {
        return [
            'status' => $this->filterValue('status') ?? 'all',
            'category' => $this->filterValue('category'),
        ];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];

        return [
            ['label' => 'Active Expenses', 'value' => Format::money($summary['active']['amount'] ?? 0), 'description' => number_format((int) ($summary['active']['entries'] ?? 0)).' entries', 'icon' => 'heroicon-m-receipt-percent', 'color' => 'warning'],
            ['label' => 'Voided', 'value' => Format::money($summary['voided']['amount'] ?? 0), 'description' => number_format((int) ($summary['voided']['entries'] ?? 0)).' entries', 'icon' => 'heroicon-m-x-circle', 'color' => 'gray'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'By category',
                'headers' => ['Category', 'Entries', 'Amount'],
                'rows' => collect($data['summary']['by_category'] ?? [])
                    ->map(fn (array $row, $key): array => [
                        str((string) ($row['category'] ?? (is_string($key) ? $key : '—')))->replace('_', ' ')->title()->toString(),
                        number_format((int) ($row['entries'] ?? 0)),
                        Format::money($row['amount'] ?? 0),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('expense_date')
                ->label('Date')
                ->formatStateUsing(fn ($state): string => Format::date($state)),
            TextColumn::make('category')
                ->badge()
                ->color('info')
                ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->title()->toString()),
            TextColumn::make('description')
                ->wrap()
                ->placeholder('—'),
            TextColumn::make('reference')
                ->color('gray')
                ->placeholder('—'),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => $state === 'voided' ? 'gray' : 'success')
                ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
            TextColumn::make('amount')
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('entered_by')
                ->label('Entered By')
                ->color('gray')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('status')->options(['active' => 'Active', 'voided' => 'Voided', 'all' => 'All']),
            SelectFilter::make('category')->options(static::categoryOptions()),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No expenses recorded';
    }
}
