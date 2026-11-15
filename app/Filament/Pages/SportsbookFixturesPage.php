<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Widgets\OddsApiCreditsWidget;
use App\Models\Site\SbCompetition;
use App\Models\Site\SbFixture;
use App\Models\Site\SbSport;
use App\Support\SportsbookFormat;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The Kadi site's sports fixtures, read from its database (`kadi_site`). Opening
 * one shows its markets, where traders suspend, reopen and override prices and
 * finance records results. Viewing needs `sportsbook.view`.
 */
class SportsbookFixturesPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const TABS = [
        'upcoming' => 'Upcoming',
        'live' => 'Live',
        'awaiting_result' => 'Awaiting result',
        'resulted' => 'Resulted',
        'cancelled' => 'Cancelled',
        'all' => 'All',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationLabel = 'Fixtures';

    protected static string|UnitEnum|null $navigationGroup = '⚽ Sportsbook';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'sportsbook/fixtures';

    protected ?string $heading = 'Fixtures';

    protected ?string $subheading = 'Matches on the Kadi site sportsbook. Times are Nairobi time.';

    protected string $view = 'filament.pages.sportsbook-fixtures-page';

    #[Url]
    public string $tab = 'upcoming';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('sportsbook.view') ?? false;
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [OddsApiCreditsWidget::class];
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'upcoming';
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

    /**
     * Limit fixtures to one tab. Mirrors {@see SportsbookFormat::fixtureState()}.
     *
     * @param  Builder<SbFixture>  $query
     */
    public static function applyTab(Builder $query, string $tab): void
    {
        // The site stores UTC, so compare against UTC.
        $now = now()->utc();
        $matchOver = $now->copy()->subHours(SportsbookFormat::MATCH_HOURS);
        $open = fn (Builder $query): Builder => $query->where('status', '!=', 'cancelled')->whereDoesntHave('results');

        match ($tab) {
            'upcoming' => $open($query)->where('kicks_off_at', '>', $now),
            'live' => $open($query)->where('kicks_off_at', '<=', $now)->where('kicks_off_at', '>', $matchOver)->whereNull('feed_completed_at'),
            'awaiting_result' => $open($query)->where('kicks_off_at', '<=', $now)
                ->where(fn (Builder $query): Builder => $query->whereNotNull('feed_completed_at')->orWhere('kicks_off_at', '<=', $matchOver)),
            'resulted' => $query->where('status', '!=', 'cancelled')->whereHas('results'),
            'cancelled' => $query->where('status', 'cancelled'),
            default => $query,
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $query = SbFixture::query()
                    ->with(['competition.sport', 'result'])
                    ->withCount([
                        'markets as open_markets_count' => fn (Builder $query): Builder => $query->where('status', 'open'),
                        'markets as suspended_markets_count' => fn (Builder $query): Builder => $query->where('status', 'suspended'),
                    ]);

                static::applyTab($query, $this->tab);

                return $query->orderBy('kicks_off_at', in_array($this->tab, ['upcoming', 'live'], true) ? 'asc' : 'desc');
            })
            ->columns([
                TextColumn::make('kicks_off_at')
                    ->label('Kick-off')
                    ->dateTime('D d M, H:i'),
                TextColumn::make('match')
                    ->label('Match')
                    ->state(fn (SbFixture $record): string => $record->home_team.' v '.$record->away_team)
                    ->weight('bold')
                    ->description(fn (SbFixture $record): string => ($record->competition?->sport?->name ?? 'Sport').' · '.($record->competition?->name ?? '—'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query): Builder => $query
                            ->where('home_team', 'like', "%{$search}%")
                            ->orWhere('away_team', 'like', "%{$search}%"))),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(fn (SbFixture $record): string => SportsbookFormat::fixtureState($record))
                    ->color(fn (string $state): string => SportsbookFormat::fixtureStateColor($state))
                    ->formatStateUsing(fn (string $state): string => SportsbookFormat::FIXTURE_STATES[$state] ?? $state),
                TextColumn::make('score')
                    ->label('Score')
                    ->state(fn (SbFixture $record): ?string => static::scoreLine($record))
                    ->placeholder('—'),
                TextColumn::make('open_markets_count')
                    ->label('Open')
                    ->alignEnd()
                    ->numeric(),
                TextColumn::make('suspended_markets_count')
                    ->label('Suspended')
                    ->alignEnd()
                    ->numeric()
                    ->color(fn (int $state): ?string => $state > 0 ? 'warning' : null),
            ])
            ->filters([
                SelectFilter::make('sport')
                    ->label('Sport')
                    ->options(fn (): array => SbSport::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('competition', fn (Builder $query): Builder => $query->where('sport_id', $data['value']))
                        : $query),
                SelectFilter::make('competition_id')
                    ->label('Competition')
                    ->searchable()
                    ->options(fn (): array => SbCompetition::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordUrl(fn (SbFixture $record): string => SportsbookFixtureDetailPage::getUrl(['fixture' => $record->id]))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('heroicon-o-trophy')
            ->emptyStateHeading('No fixtures')
            ->emptyStateDescription('No '.strtolower(self::TABS[$this->tab] ?? '').' matches.')
            ->striped();
    }

    /**
     * The result when recorded, else the live score: "2 – 1", or "Void".
     */
    public static function scoreLine(SbFixture $fixture): ?string
    {
        $result = $fixture->result;

        if ($result !== null) {
            return $result->is_void ? 'Void' : "{$result->home_score} – {$result->away_score}";
        }

        if ($fixture->home_score !== null && $fixture->away_score !== null) {
            return "{$fixture->home_score} – {$fixture->away_score}";
        }

        return null;
    }
}
