<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesOneShotApiWrites;
use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\ApiAuditLog;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * API keys for game servers (`GET /api-keys?scope=game`). A super-admin with
 * `api-keys.manage` issues a key for one game, shown once and never stored,
 * logged or retried (the API keeps no copy of its answer), and revokes keys.
 */
class GameKeysPage extends Page implements HasTable
{
    use HandlesOneShotApiWrites;
    use InteractsWithTable;

    public const KEY_WARNING = 'Store this key now on the game server. It will not be shown again.';

    public const KEY_TIMEOUT_WARNING = 'The request did not finish, so a key may have been issued. Check the key list and revoke any key you did not receive.';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Game keys';

    protected static string|UnitEnum|null $navigationGroup = '🎰 Games';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'games/keys';

    protected ?string $heading = 'Game API keys';

    protected ?string $subheading = 'Keys game servers use to place and settle wagers for one game.';

    protected string $view = 'filament.pages.games-page';

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasRole('super-admin') && $user->hasPermissionTo('api-keys.manage');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->issueKeyAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport($this->fetchKeys((int) $page, (int) $recordsPerPage)))
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('game')->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('rate_limit_multiplier')->label('Rate limit ×')->alignEnd(),
                TextColumn::make('revoked_at')->label('Revoked')->formatStateUsing(fn (?string $state): string => Format::dateTime($state))->description(fn (array $record): ?string => $record['revoked_by'] ?? null)->placeholder('—'),
            ])
            ->recordActions([$this->revokeKeyAction()])
            ->paginated([50, 100, 200])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Keys unavailable' : 'No game keys');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchKeys(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listApiKeys(['scope' => 'game', 'page' => max(1, $page), 'per_page' => min(200, max(1, $perPage))]);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('API keys list failed', ['error' => $e->getMessage()]);
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

    protected function issueKeyAction(): Action
    {
        return Action::make('issueKey')
            ->label('Issue key')
            ->icon('heroicon-o-key')
            ->visible(fn (): bool => static::canAccess())
            ->modalHeading('Issue a game key')
            ->modalDescription('The key works only on its game\'s routes. It is shown once, straight after it is issued.')
            ->modalSubmitActionLabel('Issue key')
            ->schema([
                Select::make('game')->options(fn (): array => GameProductsPage::gameOptions())->required(),
                TextInput::make('name')->label('Which server is it for?')->required()->minLength(3)->maxLength(255),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canAccess(), 403);

                $payload = ['game' => $data['game'], 'name' => trim((string) $data['name'])];

                try {
                    $issued = app(GameApiService::class)->issueGameKey($payload['game'], $payload['name']);
                } catch (GameApiException $e) {
                    ApiAuditLog::record('ApiKey', 0, 'issue_failed', $payload, $e->statusCode, array_filter(['message' => $e->apiMessage ?: $e->getMessage(), 'code' => $e->errorCode]));

                    if ($e->statusCode === 0 || $e->statusCode >= 500) {
                        Notification::make()->title('The key may have been issued')->body(self::KEY_TIMEOUT_WARNING)->warning()->persistent()->send();
                        $this->afterOneShotWrite();

                        return;
                    }

                    $this->handleOneShotFailure($e, $action, 'Could not issue the key', ['name' => 'name']);

                    return;
                }

                // Never keep or log the key itself.
                ApiAuditLog::record('ApiKey', (int) ($issued['id'] ?? 0), 'issued', $payload, 201, array_diff_key($issued, ['key' => true]));
                $this->afterOneShotWrite();

                if (blank($issued['key'] ?? null)) {
                    Notification::make()->title('Key issued, but KadiApi did not return it')->body('Revoke key "'.($issued['name'] ?? $payload['name']).'" and issue a new one.')->danger()->persistent()->send();

                    return;
                }

                // The key travels only in this modal's arguments, which are cleared when it closes.
                $this->replaceMountedAction('issuedKey', ['key' => $issued['key'], 'name' => $issued['name'] ?? $payload['name']]);
            });
    }

    /**
     * Shows a just-issued key once, with a copy button. Opened only by {@see issueKeyAction()}.
     */
    public function issuedKeyAction(): Action
    {
        return Action::make('issuedKey')
            ->visible(fn (): bool => static::canAccess())
            ->modalHeading(fn (array $arguments): string => 'Key for '.($arguments['name'] ?? 'the game server'))
            ->modalDescription(self::KEY_WARNING)
            ->modalIcon('heroicon-o-key')
            ->modalIconColor('warning')
            ->schema(fn (array $arguments): array => [
                TextEntry::make('issued_key')->hiddenLabel()->state($arguments['key'] ?? '')->fontFamily('mono')->weight('bold')->copyable()->copyMessage('Key copied'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('I have stored it')
            ->closeModalByClickingAway(false);
    }

    protected function revokeKeyAction(): Action
    {
        return Action::make('revokeKey')
            ->label('Revoke')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canAccess() && ($record['is_active'] ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => 'Revoke "'.($record['name'] ?? 'key').'"?')
            ->modalDescription('The game server using it stops working at once.')
            ->modalSubmitActionLabel('Revoke key')
            ->action(function (array $record, Action $action): void {
                abort_unless(static::canAccess(), 403);

                $id = (int) $record['id'];
                $result = $this->sendOneShotWrite($action, fn (): array => app(GameApiService::class)->revokeApiKey($id), 'ApiKey', $id, 'revoke', ['name' => $record['name'] ?? null], 'Could not revoke the key');

                if ($result !== null) {
                    Notification::make()->title('Key revoked')->success()->send();
                    $this->afterOneShotWrite();
                }
            });
    }

    protected function afterOneShotWrite(): void
    {
        $this->flushCachedTableRecords();
    }
}
