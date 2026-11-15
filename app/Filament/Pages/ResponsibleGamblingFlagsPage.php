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
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Deposits the Kadi API credited although the player's own responsible-gambling
 * rules would have refused them (paybill money cannot be turned away): to an
 * inactive or excluded player, or over a deposit limit. Staff review each and
 * close it with a note (`rg.manage`). Viewing needs `rg.view`.
 */
class ResponsibleGamblingFlagsPage extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * api ResponsibleGamblingController::FLAG_TYPES.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'rg_deposit_inactive' => 'Deposit to an inactive account',
        'rg_deposit_while_excluded' => 'Deposit during an exclusion',
        'rg_deposit_over_limit' => 'Deposit over the player\'s limit',
    ];

    public const TABS = ['open' => 'To review', 'all' => 'All'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationLabel = 'Responsible gambling';

    protected static string|UnitEnum|null $navigationGroup = '🛡️ Compliance';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'compliance/responsible-gambling';

    protected ?string $heading = 'Responsible gambling flags';

    protected ?string $subheading = 'Deposits that arrived although the player\'s own rules would have refused them.';

    protected string $view = 'filament.pages.withdrawal-approvals-page';

    #[Url]
    public string $tab = 'open';

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('rg.view') ?? false;
    }

    public static function canManage(): bool
    {
        return auth()->user()?->hasPermissionTo('rg.manage') ?? false;
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

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport($this->fetchFlags((int) $page, (int) $recordsPerPage)))
            ->columns([
                TextColumn::make('created_at')->label('Raised')->formatStateUsing(fn (?string $state): string => Format::dateTime($state)),
                TextColumn::make('type')->weight('bold')->formatStateUsing(fn (?string $state): string => self::TYPES[$state] ?? (string) $state),
                TextColumn::make('customer_id')->label('Customer')->formatStateUsing(fn ($state): string => '#'.$state)
                    ->url(fn (array $record): ?string => isset($record['customer_id']) ? AccountResource::getUrl('view', ['record' => $record['customer_id']]) : null),
                TextColumn::make('details')
                    ->state(fn (array $record): string => collect($record['details'] ?? [])->except(['resolution'])
                        ->map(fn ($value, $key): string => str_replace('_', ' ', (string) $key).': '.(is_scalar($value) ? $value : json_encode($value)))
                        ->implode(' · '))
                    ->wrap(),
                TextColumn::make('resolved_at')->label('Reviewed')->formatStateUsing(fn (?string $state): string => Format::dateTime($state))
                    ->description(fn (array $record): ?string => $record['details']['resolution']['note'] ?? null)
                    ->placeholder('Open'),
            ])
            ->recordActions([
                Action::make('resolveFlag')
                    ->label('Close')
                    ->icon('heroicon-o-check')
                    ->visible(fn (array $record): bool => static::canManage() && ($record['resolved_at'] ?? null) === null)
                    ->modalHeading('Close this flag as reviewed')
                    ->modalSubmitActionLabel('Close flag')
                    ->schema([Textarea::make('note')->label('What you did')->required()->minLength(3)->maxLength(500)->rows(2)])
                    ->action(function (array $record, array $data): void {
                        abort_unless(static::canManage(), 403);

                        $id = (int) $record['id'];
                        $payload = ['admin_user' => StaffIdentity::adminUser(auth()->user()), 'note' => trim($data['note'])];

                        try {
                            app(GameApiService::class)->resolveRgFlag($id, $payload['admin_user'], $payload['note']);
                        } catch (GameApiException $e) {
                            ApiAuditLog::record('RgFlag', $id, 'resolve', $payload, $e->statusCode, ['message' => $e->apiMessage ?: $e->getMessage()]);
                            Notification::make()->title('Could not close the flag')->body($e->apiMessage ?: $e->getMessage())->warning()->send();
                            $this->flushCachedTableRecords();

                            return;
                        }

                        ApiAuditLog::record('RgFlag', $id, 'resolve', $payload, 200, []);
                        Notification::make()->title('Flag closed')->success()->send();
                        $this->flushCachedTableRecords();
                    }),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Flags unavailable' : 'Nothing to review');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchFlags(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listRgFlags([
                'resolved' => $this->tab === 'all' ? null : 0,
                'page' => max(1, $page),
                'per_page' => min(200, max(1, $perPage)),
            ]);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('RG flags failed', ['error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['id']])
            ->values()
            ->all();

        return $data;
    }
}
