<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesOneShotApiWrites;
use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * The Kadi API's games catalogue (`GET /games?status=all`): each game, its
 * launch URL, status and order, and its products. Admins (`games.manage`) edit
 * a game. Viewing needs `games.view`.
 */
class GamesPage extends Page implements HasTable
{
    use HandlesOneShotApiWrites;
    use InteractsWithTable;

    /**
     * api Game::STATUSES.
     *
     * @var array<string, string>
     */
    public const STATUSES = ['active' => 'Active', 'suspended' => 'Suspended', 'retired' => 'Retired'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationLabel = 'Games';

    protected static string|UnitEnum|null $navigationGroup = '🎰 Games';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'games/catalogue';

    protected ?string $heading = 'Games';

    protected ?string $subheading = 'Games on the Kadi API and the products players stake on.';

    protected string $view = 'filament.pages.games-page';

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('games.view') ?? false;
    }

    public static function canManageGames(): bool
    {
        return auth()->user()?->hasPermissionTo('games.manage') ?? false;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'suspended' => 'warning',
            default => 'gray',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->fetchGames())
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (array $record): string => $record['code']),
                TextColumn::make('status')->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('products')
                    ->state(fn (array $record): string => collect($record['products'] ?? [])->pluck('name')->implode(', '))
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('launch_url')->label('Launch URL')->limit(40)->copyable()->placeholder('—'),
                TextColumn::make('sort_order')->label('Order')->alignEnd(),
            ])
            ->recordActions([$this->editGameAction()])
            ->paginated(false)
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Games unavailable' : 'No games')
            ->emptyStateDescription(fn (): ?string => $this->apiError ? 'KadiApi could not be reached. Refresh the page to try again.' : null);
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    protected function fetchGames(): Collection
    {
        try {
            $games = app(GameApiService::class)->listGames();
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('Games catalogue failed', ['error' => $e->getMessage()]);
            $this->apiError = true;

            return collect();
        }

        return collect($games)
            ->filter(fn ($game): bool => is_array($game) && isset($game['code']))
            ->mapWithKeys(fn (array $game): array => [$game['code'] => [...$game, ArrayRecord::getKeyName() => $game['code']]]);
    }

    protected function editGameAction(): Action
    {
        return Action::make('editGame')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => static::canManageGames())
            ->modalHeading(fn (array $record): string => 'Edit '.$record['name'])
            ->modalDescription('Suspending or retiring a game stops new launches of it.')
            ->fillForm(fn (array $record): array => [
                'name' => $record['name'] ?? null,
                'launch_url' => $record['launch_url'] ?? null,
                'status' => $record['status'] ?? null,
                'sort_order' => $record['sort_order'] ?? 0,
            ])
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('launch_url')->label('Launch URL')->url()->required()->maxLength(255),
                Select::make('status')->options(self::STATUSES)->required(),
                TextInput::make('sort_order')->label('Order')->integer()->minValue(0)->required(),
            ])
            ->action(function (array $record, array $data, Action $action): void {
                abort_unless(static::canManageGames(), 403);

                $payload = [
                    'name' => trim((string) $data['name']),
                    'launch_url' => trim((string) $data['launch_url']),
                    'status' => $data['status'],
                    'sort_order' => (int) $data['sort_order'],
                ];
                $result = $this->sendOneShotWrite($action, fn (): array => app(GameApiService::class)->updateGame($record['code'], $payload), 'Game', 0, 'update:'.$record['code'], $payload, 'Could not save the game', array_combine(array_keys($payload), array_keys($payload)));

                if ($result !== null) {
                    Notification::make()->title($payload['name'].' saved')->success()->send();
                    $this->afterOneShotWrite();
                }
            });
    }

    protected function afterOneShotWrite(): void
    {
        $this->flushCachedTableRecords();
    }
}
