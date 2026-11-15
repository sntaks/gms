<?php

namespace App\Filament\Pages;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\ApiAuditLog;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Paybill money credited to the house wallet as company funding
 * (`GET /finance/house-funding`). Finance (`deposits.assign`) voids one entered
 * in error, which takes it back off the house wallet (409
 * `insufficient_house_balance` when the house has spent it).
 */
class HouseFundingReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'House Funding';

    /**
     * Beside Adjustments (same sort; pages are discovered alphabetically).
     */
    protected static ?int $navigationSort = 23;

    protected ?string $heading = 'House Funding';

    public static function canVoid(): bool
    {
        $user = auth()->user();

        return ($user?->canViewFinanceReports() ?? false) && $user->hasPermissionTo('deposits.assign');
    }

    protected function reportKey(): string
    {
        return 'house-funding';
    }

    protected function exportKey(): ?string
    {
        return 'house-funding';
    }

    protected function extraFilters(): array
    {
        return ['status' => $this->filterValue('status') ?? 'active'];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];

        return [
            ['label' => 'House Funding', 'value' => Format::money($summary['active']['amount'] ?? 0), 'description' => number_format((int) ($summary['active']['entries'] ?? 0)).' entries', 'icon' => 'heroicon-m-building-office', 'color' => 'primary'],
            ['label' => 'House Wallet', 'value' => Format::money($summary['house_wallet_balance'] ?? 0), 'description' => 'Balance now', 'icon' => 'heroicon-m-wallet', 'color' => 'info'],
            ['label' => 'Voided', 'value' => Format::money($summary['voided']['amount'] ?? 0), 'description' => number_format((int) ($summary['voided']['entries'] ?? 0)).' entries', 'icon' => 'heroicon-m-x-circle', 'color' => 'gray'],
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            // Key rows by funding id so Void can never land on a different row if the list shifts.
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
            TextColumn::make('created_at')->label('Recorded')->formatStateUsing(fn ($state): string => Format::dateTime($state))
                ->description(fn (array $record): ?string => $record['recorded_by'] ?? null),
            TextColumn::make('trans_id')->label('M-Pesa receipt')->fontFamily('mono')->copyable()
                ->description(fn (array $record): ?string => $record['bill_ref_no'] ?? null),
            TextColumn::make('amount')->alignEnd()->weight('bold')->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('note')->wrap()->placeholder('—'),
            TextColumn::make('voided_at')->label('Voided')->formatStateUsing(fn ($state): string => Format::dateTime($state))
                ->description(fn (array $record): ?string => $record['void_reason'] ?? null)
                ->placeholder('—'),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [SelectFilter::make('status')->options(['active' => 'Active', 'voided' => 'Voided', 'all' => 'All'])];
    }

    protected function voidAction(): Action
    {
        return Action::make('voidHouseFunding')
            ->label('Void')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canVoid() && ($record['voided_at'] ?? null) === null)
            ->modalHeading(fn (array $record): string => 'Void house funding of '.Format::money($record['amount'] ?? 0).'?')
            ->modalDescription('The amount is taken back off the house wallet and the deposit becomes unmatched again. Only void funding recorded by mistake.')
            ->modalSubmitActionLabel('Void funding')
            ->fillForm(fn (): array => ['idempotency_key' => Str::uuid()->toString()])
            ->schema([
                Hidden::make('idempotency_key'),
                Textarea::make('reason')->required()->minLength(3)->maxLength(255)->rows(2),
            ])
            ->action(function (array $data, array $record, Action $action): void {
                abort_unless(static::canVoid(), 403);

                $id = (int) $record['id'];
                $payload = ['reason' => trim($data['reason']), 'idempotency_key' => $data['idempotency_key']];

                try {
                    app(GameApiService::class)->voidHouseFunding($id, $payload['reason'], $payload['idempotency_key']);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('HouseFunding', $id, 'void', $payload, $e->statusCode, array_filter(['message' => $e->apiMessage ?: $e->getMessage(), 'code' => $e->errorCode]));

                    // A void carries an Idempotency-Key, so a rate limit or outage keeps the modal open and a resubmit replays it.
                    if ($e->statusCode === 0 || $e->statusCode === 429 || $e->statusCode >= 500) {
                        Notification::make()->title('Could not void the funding')->body(($e->apiMessage ?: $e->getMessage()).' Submit again to retry safely.')->warning()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Could not void the funding')->body($e->apiMessage ?: $e->getMessage())->warning()->send();
                    $this->refreshAfterWrite();

                    return;
                }

                ApiAuditLog::record('HouseFunding', $id, 'void', $payload, 200, []);
                Notification::make()->title('House funding voided')->success()->send();
                $this->refreshAfterWrite();
            });
    }

    protected function refreshAfterWrite(): void
    {
        $this->cachedReport = null;
        $this->flushCachedTableRecords();
    }

    protected function emptyHeading(): string
    {
        return 'No house funding in this period';
    }
}
