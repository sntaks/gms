<?php

namespace App\Filament\Pages;

use App\Models\Site\SbBet;
use App\Support\SportsbookFormat;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Sports bets placed on the Kadi site, read from its database. Each bet is a
 * Kadi API wager (`wager_reference`); opening one shows its legs, where finance
 * voids legs and cancels open bets. Viewing needs `sportsbook.view`.
 */
class SportsbookBetsPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const TABS = [
        'all' => 'All',
        'open' => 'Open',
        'pending' => 'Being checked',
        'won' => 'Won',
        'lost' => 'Lost',
        'void' => 'Void',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
    ];

    /**
     * `sb_bets.status` values.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Being checked',
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
        'void' => 'Void',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Bets';

    protected static string|UnitEnum|null $navigationGroup = '⚽ Sportsbook';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'sportsbook/bets';

    protected ?string $heading = 'Sports bets';

    protected ?string $subheading = 'Bets placed on the Kadi site sportsbook. Amounts in KES; times in Nairobi time.';

    protected string $view = 'filament.pages.sportsbook-bets-page';

    #[Url]
    public string $tab = 'all';

    public static function canAccess(): bool
    {
        return SportsbookFixturesPage::canAccess();
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'won' => 'success',
            'lost' => 'danger',
            'open' => 'info',
            'pending' => 'warning',
            default => 'gray',
        };
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'all';
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

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SbBet::query()
                ->with('user')
                ->withCount('legs')
                ->when($this->tab !== 'all', fn (Builder $query): Builder => $query->where('status', $this->tab))
                ->latest('placed_at'))
            ->columns([
                TextColumn::make('placed_at')
                    ->label('Placed')
                    ->dateTime('d M, H:i'),
                TextColumn::make('reference')
                    ->label('Bet')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->description(fn (SbBet $record): string => ucfirst((string) $record->type).' · '.$record->legs_count.' '.str('leg')->plural($record->legs_count))
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::search($query, $search)),
                TextColumn::make('user.name')
                    ->label('Player')
                    ->placeholder('Closed account')
                    ->description(fn (SbBet $record): ?string => $record->user?->linked_id === null ? null : 'Customer #'.$record->user->linked_id),
                TextColumn::make('product')
                    ->label('Product')
                    ->badge()
                    ->state(fn (SbBet $record): string => $record->kadiProduct())
                    ->color(fn (string $state): string => $state === 'sportsbook_live' ? 'danger' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === 'sportsbook_live' ? 'In play' : 'Pre-match'),
                TextColumn::make('stake')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state, SbBet $record): string => SportsbookFormat::cents($state).($record->free_bet_id !== null ? ' (free bet)' : '')),
                TextColumn::make('total_odds')
                    ->label('Odds')
                    ->alignEnd(),
                TextColumn::make('payout')
                    ->label('Payout')
                    ->alignEnd()
                    ->state(fn (SbBet $record): ?int => $record->payout ?? ($record->status === 'open' ? $record->potential_payout : null))
                    ->formatStateUsing(fn (?int $state): string => SportsbookFormat::cents($state))
                    ->description(fn (SbBet $record): ?string => $record->status === 'open' ? 'potential' : null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state))
                    ->description(fn (SbBet $record): ?string => SportsbookFormat::rejectReason($record->reject_reason)),
            ])
            ->filters([
                SelectFilter::make('product')
                    ->label('Product')
                    ->options(['sportsbook' => 'Pre-match', 'sportsbook_live' => 'In play'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'sportsbook' => $query->whereNull('product'),
                        'sportsbook_live' => $query->where('product', 'sportsbook_live'),
                        default => $query,
                    }),
                SelectFilter::make('type')
                    ->options(['single' => 'Single', 'multi' => 'Multi']),
                Filter::make('placed')
                    ->schema([
                        DatePicker::make('from')->label('Placed from'),
                        DatePicker::make('until')->label('Placed until'),
                    ])
                    // A Nairobi day, compared in UTC as the site stores it.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('placed_at', '>=', Carbon::parse($date, config('app.timezone'))->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->where('placed_at', '<=', Carbon::parse($date, config('app.timezone'))->endOfDay()->utc()))),
            ])
            ->recordUrl(fn (SbBet $record): string => SportsbookBetDetailPage::getUrl(['bet' => $record->id]))
            ->searchPlaceholder('Bet, wager reference, player or customer #')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('heroicon-o-ticket')
            ->emptyStateHeading('No bets')
            ->striped();
    }

    /**
     * A bet reference, a Kadi API wager reference, a player's name or their Kadi API customer id.
     *
     * @param  Builder<SbBet>  $query
     * @return Builder<SbBet>
     */
    public static function search(Builder $query, string $search): Builder
    {
        $search = trim($search);
        $customerId = ltrim($search, '#');

        return $query->where(fn (Builder $query): Builder => $query
            ->where('reference', 'like', "%{$search}%")
            ->orWhere('wager_reference', $search)
            ->orWhereHas('user', fn (Builder $query): Builder => $query
                ->where('name', 'like', "%{$search}%")
                ->when(ctype_digit($customerId), fn (Builder $query): Builder => $query->orWhere('linked_id', (int) $customerId))));
    }
}
