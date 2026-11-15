<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesSiteWrites;
use App\Models\Site\SbFixture;
use App\Models\Site\SbJackpot;
use App\Support\SportsbookFormat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The Kadi site's sportsbook jackpots: pick 1, X or 2 on a set of football
 * matches for one entry price, with prize pools by matches right. Traders
 * (`sportsbook.trade`) draft them; `sportsbook.jackpots` publishes and cancels.
 */
class SportsbookJackpotsPage extends Page implements HasTable
{
    use HandlesSiteWrites;
    use InteractsWithTable;

    public const TABS = [
        'all' => 'All',
        'draft' => 'Drafts',
        'open' => 'Open',
        'settled' => 'Settled',
        'cancelled' => 'Cancelled',
    ];

    /**
     * `sb_jackpots.status` values.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'open' => 'Open',
        'settled' => 'Settled',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Site field => form field, for 422 errors on the draft form.
     *
     * @var array<string, string>
     */
    public const FORM_FIELDS = [
        'name' => 'name',
        'entry_price' => 'entry_price',
        'void_limit' => 'void_limit',
        'prize_tiers' => 'prize_tiers',
        'fixture_ids' => 'fixture_ids',
    ];

    /**
     * Jackpot picks are 1, X or 2, so only football matches (which can be drawn) are offered.
     */
    public const SPORT_SLUG = 'football';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Jackpots';

    protected static string|UnitEnum|null $navigationGroup = '⚽ Sportsbook';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'sportsbook/jackpots';

    protected ?string $heading = 'Jackpots';

    protected ?string $subheading = 'Sportsbook jackpots on the Kadi site. Amounts in KES.';

    protected string $view = 'filament.pages.sportsbook-jackpots-page';

    #[Url]
    public string $tab = 'all';

    public static function canAccess(): bool
    {
        return SportsbookFixturesPage::canAccess();
    }

    /**
     * Publishing and cancelling.
     */
    public static function canRunJackpots(): bool
    {
        return auth()->user()?->hasPermissionTo('sportsbook.jackpots') ?? false;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'open' => 'success',
            'draft' => 'warning',
            'settled' => 'info',
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

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->createJackpotAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SbJackpot::query()
                ->withCount(['fixtures', 'entries'])
                ->withSum(['entries as stakes' => fn (Builder $query): Builder => $query->whereNotIn('status', ['pending', 'rejected'])], 'stake')
                ->when($this->tab !== 'all', fn (Builder $query): Builder => $query->where('status', $this->tab))
                ->latest('id'))
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->description(fn (SbJackpot $record): string => $record->fixtures_count.' '.str('match')->plural($record->fixtures_count))
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('entry_price')
                    ->label('Entry')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => SportsbookFormat::cents($state)),
                TextColumn::make('prizes')
                    ->label('Prizes')
                    ->alignEnd()
                    ->state(fn (SbJackpot $record): int => static::totalPrizes($record))
                    ->formatStateUsing(fn (int $state): string => SportsbookFormat::cents($state)),
                TextColumn::make('entries_count')
                    ->label('Entries')
                    ->alignEnd()
                    ->numeric(),
                TextColumn::make('stakes')
                    ->label('Stakes')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => SportsbookFormat::cents((int) $state)),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime('d M, H:i')
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (SbJackpot $record): string => SportsbookJackpotDetailPage::getUrl(['jackpot' => $record->id]))
            ->paginated([25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon('heroicon-o-star')
            ->emptyStateHeading('No jackpots')
            ->striped();
    }

    public static function totalPrizes(SbJackpot $jackpot): int
    {
        return (int) collect($jackpot->prize_tiers ?? [])->sum(fn (array $tier): int => (int) ($tier['pool'] ?? 0));
    }

    /**
     * Upcoming football matches, as the site allows in a draft: scheduled and not yet kicked off.
     *
     * @param  list<int>  $alsoInclude  Matches already in the draft, listed even if they have since kicked off.
     * @return array<int, string>
     */
    public static function fixtureOptions(array $alsoInclude = []): array
    {
        return SbFixture::query()
            ->with('competition')
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query
                    ->where('status', 'scheduled')
                    ->where('kicks_off_at', '>', now()->utc())
                    ->whereHas('competition.sport', fn (Builder $query): Builder => $query->where('slug', self::SPORT_SLUG)))
                ->orWhereKey($alsoInclude))
            ->orderBy('kicks_off_at')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (SbFixture $fixture): array => [$fixture->id => $fixture->kicks_off_at->timezone(config('app.timezone'))->format('D d M H:i')
                .' · '.$fixture->home_team.' v '.$fixture->away_team.' ('.($fixture->competition?->name ?? '—').')'])
            ->all();
    }

    /**
     * The draft form, shared by creating and editing. Amounts are entered in KES and sent in cents.
     *
     * @param  list<int>  $currentFixtures
     * @return list<Component|Field>
     */
    public static function draftFormFields(array $currentFixtures = []): array
    {
        return [
            static::requestIdField(),
            TextInput::make('name')
                ->required()
                ->maxLength(100),
            TextInput::make('entry_price')
                ->label('Entry price (KES)')
                ->numeric()
                ->minValue(1)
                ->step(0.01)
                ->required(),
            TextInput::make('void_limit')
                ->label('Void limit')
                ->helperText('More void matches than this and the jackpot is cancelled and every entry refunded.')
                ->integer()
                ->minValue(0)
                ->maxValue(20)
                ->required(),
            Repeater::make('prize_tiers')
                ->label('Prize tiers')
                ->helperText('One tier for every match right is required to publish; each tier needs a different number of matches right.')
                ->schema([
                    TextInput::make('correct')
                        ->label('Matches right')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(30)
                        ->required(),
                    TextInput::make('pool')
                        ->label('Prize pool (KES)')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->required(),
                ])
                ->columns(2)
                ->minItems(1)
                ->maxItems(5)
                ->defaultItems(1)
                ->required(),
            Select::make('fixture_ids')
                ->label('Matches')
                ->helperText('Upcoming football matches only. The site numbers them in kick-off order and checks the match count when publishing.')
                ->multiple()
                ->searchable()
                ->options(fn (): array => static::fixtureOptions($currentFixtures))
                ->minItems(1)
                ->maxItems(30)
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, entry_price: int, void_limit: int, prize_tiers: list<array{correct: int, pool: int}>, fixture_ids: list<int>}
     */
    public static function draftPayload(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'entry_price' => static::toCents($data['entry_price']),
            'void_limit' => (int) $data['void_limit'],
            'prize_tiers' => collect($data['prize_tiers'] ?? [])
                ->map(fn (array $tier): array => ['correct' => (int) $tier['correct'], 'pool' => static::toCents($tier['pool'])])
                ->values()
                ->all(),
            'fixture_ids' => array_values(array_map('intval', $data['fixture_ids'] ?? [])),
        ];
    }

    public static function toCents(mixed $kes): int
    {
        return (int) round(((float) $kes) * 100);
    }

    protected function createJackpotAction(): Action
    {
        return Action::make('createJackpot')
            ->label('New jackpot')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => static::canTrade())
            ->modalHeading('Draft a jackpot')
            ->modalDescription('Players never see a draft. Publish it from its page once it is complete.')
            ->modalSubmitActionLabel('Save draft')
            ->fillForm(fn (): array => static::newSiteRequest(['void_limit' => 3]))
            ->schema(static::draftFormFields())
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canTrade(), 403);

                $result = $this->sendSiteWrite($action, $data, 'POST', 'jackpots', static::draftPayload($data), 'SbJackpot', 0, 'draft', 'Could not save the draft', self::FORM_FIELDS);

                if ($result === null) {
                    return;
                }

                Notification::make()->title('Draft saved')->success()->send();

                $id = $result->data['jackpot']['id'] ?? null;

                if ($id !== null) {
                    $this->redirect(SportsbookJackpotDetailPage::getUrl(['jackpot' => (int) $id]), navigate: true);

                    return;
                }

                $this->afterSiteWrite();
            });
    }

    protected function afterSiteWrite(): void
    {
        $this->flushCachedTableRecords();
    }
}
