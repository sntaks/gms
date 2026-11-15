<?php

namespace App\Filament\Pages;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Wagers on the Kadi API across games (`GET /wagers`): slots, scratch cards,
 * crash, the sportsbook and prediction markets. Opening one shows its
 * settlements and ledger entries. Viewing needs `games.view`.
 */
class GameWagersPage extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * api Wager::STATUSES.
     *
     * @var array<string, string>
     */
    public const STATUSES = ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'void' => 'Void'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Wagers';

    protected static string|UnitEnum|null $navigationGroup = '🎰 Games';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'games/wagers';

    protected ?string $heading = 'Wagers';

    protected ?string $subheading = 'Every wager on the Kadi API, newest first. Amounts in KES.';

    protected string $view = 'filament.pages.games-page';

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return GamesPage::canAccess();
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'won' => 'success',
            'lost' => 'danger',
            'open' => 'info',
            default => 'gray',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport($this->fetchWagers((int) $page, (int) $recordsPerPage)))
            ->columns([
                TextColumn::make('placed_at')->label('Placed')->formatStateUsing(fn (?string $state): string => Format::dateTime($state)),
                TextColumn::make('reference')->fontFamily('mono')->limit(18)->tooltip(fn (array $record): ?string => $record['reference'] ?? null)
                    ->description(fn (array $record): string => ($record['product'] ?? '').(($record['is_tombstone'] ?? false) ? ' · tombstone' : '')),
                TextColumn::make('customer_id')->label('Customer')->formatStateUsing(fn ($state): string => '#'.$state),
                TextColumn::make('stake')->alignEnd()->formatStateUsing(fn ($state, array $record): string => ($record['funding'] ?? null) === 'free_bet'
                    ? Format::money($record['free_bet_amount'] ?? 0).' (free bet)'
                    : Format::money($state)),
                TextColumn::make('payout')->alignEnd()->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('status')->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
            ])
            ->filters([
                SelectFilter::make('game')->options(fn (): array => GameProductsPage::gameOptions()),
                SelectFilter::make('status')->options(self::STATUSES),
                Filter::make('lookup')
                    ->schema([
                        TextInput::make('reference')->label('Wager reference'),
                        TextInput::make('customer')->label('Customer id')->integer()->minValue(1),
                    ]),
                Filter::make('placed')
                    ->schema([
                        DatePicker::make('from')->label('Placed from'),
                        DatePicker::make('to')->label('Placed until'),
                    ]),
            ])
            ->recordUrl(fn (array $record): string => GameWagerDetailPage::getUrl(['wager' => (int) $record['id']]))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Wagers unavailable' : 'No wagers')
            ->striped();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchWagers(int $page, int $perPage): array
    {
        $filters = $this->tableFilters ?? [];

        try {
            $data = app(GameApiService::class)->listWagers([
                'game' => $filters['game']['value'] ?? null,
                'status' => $filters['status']['value'] ?? null,
                'reference' => filled($filters['lookup']['reference'] ?? null) ? trim($filters['lookup']['reference']) : null,
                'customer' => $filters['lookup']['customer'] ?? null,
                'from' => $filters['placed']['from'] ?? null,
                'to' => $filters['placed']['to'] ?? null,
                'page' => max(1, $page),
                'per_page' => min(200, max(1, $perPage)),
            ]);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('Wagers list failed', ['error' => $e->getMessage()]);
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
