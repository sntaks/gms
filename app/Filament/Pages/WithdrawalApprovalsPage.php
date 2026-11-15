<?php

namespace App\Filament\Pages;

use App\Exceptions\GameApiException;
use App\Filament\Resources\Accounts\AccountResource;
use App\Services\GameApiService;
use App\Support\ApiAuditLog;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use App\Support\StaffIdentity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
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
 * Withdrawals the Kadi API held for an admin (KES 20,000 or more). Finance
 * (`withdrawal-approvals.decide`) approves, which sends the payout to M-Pesa,
 * or rejects, which refunds the player. An approval whose M-Pesa answer never
 * came back (504 `payout_unknown`) must never be retried: the statement says
 * whether it was paid. Viewing needs `withdrawal-approvals.view`.
 */
class WithdrawalApprovalsPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const TABS = [
        'awaiting' => 'Waiting for a decision',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled by the player',
    ];

    public const PAYOUT_STATUSES = [
        'pending' => 'Held',
        'sent' => 'Sent to M-Pesa',
        'failed' => 'Refunded',
        'cancelled' => 'Cancelled',
        'unconfirmed' => 'Unconfirmed: check the M-Pesa statement',
    ];

    public const APPROVE_WARNING = 'The payout is sent to the player\'s M-Pesa at once. If M-Pesa does not answer, do not approve again: check the M-Pesa statement.';

    public const PAYOUT_UNKNOWN = 'Approved, but M-Pesa did not answer: the payout may or may not have been made. Do not retry. Check the M-Pesa statement before doing anything else.';

    public const NO_ANSWER = 'The Kadi API did not answer, so it is not known whether this went through. The list has been reloaded: check the withdrawal\'s status before trying again.';

    /**
     * Held this long, a withdrawal is flagged as overdue.
     */
    public const OVERDUE_HOURS = 24;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationLabel = 'Held withdrawals';

    protected static string|UnitEnum|null $navigationGroup = '🛡️ Compliance';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'compliance/held-withdrawals';

    protected ?string $heading = 'Held withdrawals';

    protected ?string $subheading = 'Withdrawals of KES 20,000 or more wait here for an admin before they are paid.';

    protected string $view = 'filament.pages.withdrawal-approvals-page';

    #[Url]
    public string $tab = 'awaiting';

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('withdrawal-approvals.view') ?? false;
    }

    public static function canDecide(): bool
    {
        return auth()->user()?->hasPermissionTo('withdrawal-approvals.decide') ?? false;
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'awaiting';
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

    public static function isOverdue(array $record): bool
    {
        return ($record['status'] ?? null) === 'awaiting'
            && filled($record['requested_at'] ?? null)
            && Carbon::parse($record['requested_at'])->lt(now()->subHours(self::OVERDUE_HOURS));
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport($this->fetchApprovals((int) $page, (int) $recordsPerPage)))
            ->columns([
                TextColumn::make('requested_at')
                    ->label('Requested')
                    ->formatStateUsing(fn (?string $state): string => Format::dateTime($state))
                    ->description(fn (array $record): ?string => filled($record['requested_at'] ?? null) ? Carbon::parse($record['requested_at'])->diffForHumans() : null)
                    ->color(fn (array $record): ?string => static::isOverdue($record) ? 'danger' : null),
                TextColumn::make('name')
                    ->label('Player')
                    ->weight('bold')
                    ->description(fn (array $record): string => Format::playerNo($record['player_no'] ?? null).' · '.Format::maskedPhone($record['phone_no'] ?? null))
                    ->url(fn (array $record): ?string => isset($record['customer_id']) ? AccountResource::getUrl('view', ['record' => $record['customer_id']]) : null),
                TextColumn::make('amount')->alignEnd()->weight('bold')->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('payout_status')
                    ->label('Money')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'sent' => 'success',
                        'pending' => 'warning',
                        'unconfirmed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => self::PAYOUT_STATUSES[$state] ?? (string) $state)
                    ->description(fn (array $record): ?string => $record['payout_error'] ?? null),
                TextColumn::make('decided_by')
                    ->label('Decided')
                    ->state(fn (array $record): ?string => $record['admin_user'] ?? $record['decided_by'] ?? null)
                    ->description(fn (array $record): ?string => $record['reason'] ?? null)
                    ->placeholder('—'),
            ])
            ->recordActions([$this->approveAction(), $this->rejectAction()])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Held withdrawals unavailable' : ($this->tab === 'awaiting' ? 'Nothing is waiting' : 'None'))
            ->striped();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchApprovals(int $page, int $perPage): array
    {
        $api = app(GameApiService::class);
        $filters = ['page' => max(1, $page), 'per_page' => min(200, max(1, $perPage))];

        try {
            $data = $this->tab === 'awaiting'
                ? $api->listPendingWithdrawalApprovals($filters)
                : $api->listWithdrawalApprovals([...$filters, 'status' => $this->tab]);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('Held withdrawals list failed', ['tab' => $this->tab, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['withdrawal_id']))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['withdrawal_id']])
            ->values()
            ->all();

        return $data;
    }

    protected function approveAction(): Action
    {
        return Action::make('approveWithdrawal')
            ->label('Approve')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (array $record): bool => static::canDecide() && ($record['status'] ?? null) === 'awaiting')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => 'Pay '.Format::money($record['amount'] ?? 0).' to '.($record['name'] ?? 'the player').'?')
            ->modalDescription(self::APPROVE_WARNING)
            ->modalSubmitActionLabel('Approve and pay')
            ->action(function (array $record): void {
                abort_unless(static::canDecide(), 403);

                $id = (int) $record['withdrawal_id'];
                $adminUser = StaffIdentity::adminUser(auth()->user());

                try {
                    app(GameApiService::class)->approveWithdrawal($id, $adminUser);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('WithdrawalApproval', $id, 'approve', ['admin_user' => $adminUser], $e->statusCode, array_filter(['message' => $e->apiMessage ?: $e->getMessage(), 'code' => $e->errorCode]));
                    $this->approvalFailure($e);
                    $this->flushCachedTableRecords();

                    return;
                }

                ApiAuditLog::record('WithdrawalApproval', $id, 'approve', ['admin_user' => $adminUser], 200, ['payout' => 'sent']);
                Notification::make()->title('Approved')->body('The payout was sent to M-Pesa.')->success()->send();
                $this->flushCachedTableRecords();
            });
    }

    /**
     * Tell staff what an approval that did not return 200 means for the money.
     */
    protected function approvalFailure(GameApiException $e): void
    {
        $message = $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();

        match (true) {
            $e->statusCode === 504 || $e->errorCode === 'payout_unknown' => Notification::make()->title('Payout not confirmed')->body(self::PAYOUT_UNKNOWN)->danger()->persistent()->send(),
            $e->statusCode === 502 => Notification::make()->title('M-Pesa refused the payout')->body('The withdrawal was approved but M-Pesa refused it, so the player has been refunded.')->warning()->persistent()->send(),
            $e->statusCode === 0 || $e->statusCode >= 500 => Notification::make()->title('Not confirmed')->body(self::NO_ANSWER)->warning()->persistent()->send(),
            default => Notification::make()->title('Could not approve')->body($message)->warning()->send(),
        };
    }

    protected function rejectAction(): Action
    {
        return Action::make('rejectWithdrawal')
            ->label('Reject')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canDecide() && ($record['status'] ?? null) === 'awaiting')
            ->modalHeading(fn (array $record): string => 'Reject '.Format::money($record['amount'] ?? 0).' for '.($record['name'] ?? 'the player').'?')
            ->modalDescription('The player is refunded at once.')
            ->modalSubmitActionLabel('Reject and refund')
            ->schema([Textarea::make('reason')->required()->minLength(3)->maxLength(500)->rows(2)])
            ->action(function (array $record, array $data): void {
                abort_unless(static::canDecide(), 403);

                $id = (int) $record['withdrawal_id'];
                $payload = ['admin_user' => StaffIdentity::adminUser(auth()->user()), 'reason' => trim($data['reason'])];

                try {
                    app(GameApiService::class)->rejectWithdrawal($id, $payload['admin_user'], $payload['reason']);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('WithdrawalApproval', $id, 'reject', $payload, $e->statusCode, array_filter(['message' => $e->apiMessage ?: $e->getMessage(), 'code' => $e->errorCode]));
                    $e->statusCode === 0 || $e->statusCode >= 500
                        ? Notification::make()->title('Not confirmed')->body(self::NO_ANSWER)->warning()->persistent()->send()
                        : Notification::make()->title('Could not reject')->body($e->apiMessage ?: $e->getMessage())->warning()->send();
                    $this->flushCachedTableRecords();

                    return;
                }

                ApiAuditLog::record('WithdrawalApproval', $id, 'reject', $payload, 200, ['refunded' => true]);
                Notification::make()->title('Rejected')->body('The player has been refunded.')->success()->send();
                $this->flushCachedTableRecords();
            });
    }
}
