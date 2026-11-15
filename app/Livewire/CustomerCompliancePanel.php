<?php

namespace App\Livewire;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\ApiAuditLog;
use App\Support\Format;
use App\Support\StaffIdentity;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * A player's compliance state on the Kadi API, on the customer page: status and
 * its history, whether they can play, any cool-off or self-exclusion, and their
 * deposit limits. `accounts.status` changes the status (with a reason, kept in
 * the history); `rg.manage` starts a break on the player's request.
 */
class CustomerCompliancePanel extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /**
     * api Customer::STATUSES. Blocked and fraud may not play, deposit or withdraw.
     *
     * @var array<int, string>
     */
    public const STATUSES = [1 => 'Active', 2 => 'Blocked', 3 => 'Confirmed', 4 => 'Closed', 5 => 'Fraud', 6 => 'Lead'];

    /**
     * api config responsible_gambling.exclusions.
     *
     * @var array<string, array<string, string>>
     */
    public const BREAKS = [
        'cool_off' => ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'],
        'self_exclusion' => ['6m' => '6 months', '1y' => '1 year', '5y' => '5 years'],
    ];

    public const BREAK_WARNING = 'Only on the player\'s own request. A break can be made longer but never shorter, a running self-exclusion stays a self-exclusion, and it also applies to the player\'s other accounts with the same phone or ID.';

    public int $customerId;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $state = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $exclusions = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $statusChanges = [];

    public bool $apiError = false;

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;
        $this->load();
    }

    public static function canChangeStatus(): bool
    {
        return auth()->user()?->hasPermissionTo('accounts.status') ?? false;
    }

    public static function canStartBreak(): bool
    {
        return auth()->user()?->hasPermissionTo('rg.manage') ?? false;
    }

    public function load(): void
    {
        $api = app(GameApiService::class);

        try {
            $this->state = $api->getCustomerResponsibleGambling($this->customerId);
            $this->exclusions = array_values($api->listCustomerExclusions($this->customerId)['items'] ?? []);
            $this->statusChanges = array_values($api->listCustomerStatusChanges($this->customerId)['items'] ?? []);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('Customer compliance failed', ['customer' => $this->customerId, 'error' => $e->getMessage()]);
            $this->apiError = true;
        }
    }

    public static function statusLabel(mixed $status): string
    {
        return self::STATUSES[(int) $status] ?? 'Unknown ('.$status.')';
    }

    public static function breakLabel(?string $type): string
    {
        return $type === 'self_exclusion' ? 'Self-exclusion' : 'Cool-off';
    }

    /**
     * "Day: KES 5,000.00 · KES 3,000.00 left · changes to no limit on 17 Nov 2026, 09:00".
     *
     * @param  array<string, mixed>  $limit
     */
    public static function limitLine(array $limit): string
    {
        $amount = fn (mixed $value): string => $value === null ? 'no limit' : Format::money($value);
        $parts = [ucfirst((string) ($limit['period'] ?? '')).': '.$amount($limit['amount'] ?? null)];

        if (isset($limit['remaining'])) {
            $parts[] = Format::money($limit['remaining']).' left';
        }

        if (($limit['pending_from'] ?? null) !== null) {
            $parts[] = 'changes to '.$amount($limit['pending_amount'] ?? null).' on '.Format::dateTime($limit['pending_from']);
        }

        return implode(' · ', $parts);
    }

    /**
     * "10 Nov 2026, 09:00: Lead → Active · Jane Admin (GMS #3): KYC done".
     *
     * @param  array<string, mixed>  $change
     */
    public static function changeLine(array $change): string
    {
        $by = $change['admin_user'] ?? $change['actor'] ?? 'System';

        return Format::dateTime($change['created_at'] ?? null).': '.static::statusLabel($change['from_status'] ?? null).' → '.static::statusLabel($change['to_status'] ?? null)
            .' · '.$by.(filled($change['reason'] ?? null) ? ': '.$change['reason'] : '');
    }

    public function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->icon('heroicon-o-user-circle')
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => static::canChangeStatus() && ! $this->apiError)
            ->modalHeading('Change the player\'s status')
            ->modalDescription('Blocked and Fraud stop the player playing, depositing and withdrawing. The reason is kept in the status history.')
            ->fillForm(fn (): array => ['status' => (int) ($this->state['status'] ?? 1)])
            ->schema([
                Select::make('status')->options(self::STATUSES)->required(),
                Textarea::make('reason')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (array $data): void {
                abort_unless(static::canChangeStatus(), 403);

                $payload = ['status' => (int) $data['status'], 'reason' => trim($data['reason']), 'admin_user' => StaffIdentity::adminUser(auth()->user())];

                try {
                    $result = app(GameApiService::class)->updateCustomerStatus($this->customerId, $payload['status'], $payload['reason'], $payload['admin_user']);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('CustomerStatus', $this->customerId, 'change', $payload, $e->statusCode, ['message' => $e->apiMessage ?: $e->getMessage()]);
                    Notification::make()->title('Could not change the status')->body($e->apiMessage ?: $e->getMessage())->warning()->send();
                    $this->load();

                    return;
                }

                ApiAuditLog::record('CustomerStatus', $this->customerId, 'change', $payload, 200, $result);
                Notification::make()->title('Status: '.static::statusLabel($result['status'] ?? $payload['status']))->success()->send();
                $this->load();
            });
    }

    public function startBreakAction(): Action
    {
        return Action::make('startBreak')
            ->label('Start a break')
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->size('sm')
            ->visible(fn (): bool => static::canStartBreak() && ! $this->apiError)
            ->modalHeading('Start a cool-off or self-exclusion')
            ->modalDescription(self::BREAK_WARNING)
            ->modalSubmitActionLabel('Start break')
            ->schema([
                Select::make('type')
                    ->options(['cool_off' => 'Cool-off', 'self_exclusion' => 'Self-exclusion'])
                    ->required()
                    ->live(),
                Select::make('duration')
                    ->options(fn (Get $get): array => self::BREAKS[$get('type')] ?? [])
                    ->required(),
            ])
            ->action(function (array $data): void {
                abort_unless(static::canStartBreak(), 403);

                $payload = ['type' => $data['type'], 'duration' => $data['duration'], 'admin_user' => StaffIdentity::adminUser(auth()->user())];

                try {
                    $result = app(GameApiService::class)->startCustomerExclusion($this->customerId, $payload['type'], $payload['duration']);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('CustomerExclusion', $this->customerId, 'start', $payload, $e->statusCode, array_filter(['message' => $e->apiMessage ?: $e->getMessage(), 'code' => $e->errorCode]));
                    Notification::make()->title('Could not start the break')->body($e->apiMessage ?: $e->getMessage())->warning()->send();
                    $this->load();

                    return;
                }

                ApiAuditLog::record('CustomerExclusion', $this->customerId, 'start', $payload, 201, $result);
                Notification::make()
                    ->title(static::breakLabel($result['type'] ?? $payload['type']).' started')
                    ->body(isset($result['ends_at']) ? 'Until '.Format::dateTime($result['ends_at']).'.' : null)
                    ->success()
                    ->send();
                $this->load();
            });
    }

    public function render(): View
    {
        return view('livewire.customer-compliance-panel');
    }
}
