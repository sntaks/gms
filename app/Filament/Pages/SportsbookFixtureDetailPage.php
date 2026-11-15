<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesSiteWrites;
use App\Models\Site\SbFixture;
use App\Models\Site\SbMarket;
use App\Models\Site\SbSelection;
use App\Support\SportsbookFormat;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * One Kadi site fixture and its markets. Traders (`sportsbook.trade`) suspend,
 * reopen and override prices; finance (`sportsbook.settle`) records or corrects
 * the result. Every action goes through the site's signed back-office API,
 * which settles bets through its outbox to the Kadi API; GMS moves no money.
 */
class SportsbookFixtureDetailPage extends Page implements HasTable
{
    use HandlesSiteWrites;
    use InteractsWithTable;

    public const CORRECTION_WARNING = 'This fixture already has a result. Recording again corrects it: every settled bet on it is reversed and settled again on the new result.';

    public const PRICES_HELP = 'Leave a price empty to clear its override and go back to the feed price.';

    /**
     * Site field => form field, for 422 errors on the result form.
     *
     * @var array<string, string>
     */
    public const RESULT_FIELDS = [
        'home_score' => 'home_score',
        'away_score' => 'away_score',
        'half_time_home_score' => 'half_time_home_score',
        'half_time_away_score' => 'half_time_away_score',
        'note' => 'note',
    ];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sportsbook/fixtures/detail';

    protected string $view = 'filament.pages.sportsbook-fixture-detail-page';

    #[Url]
    public ?int $fixture = null;

    protected ?SbFixture $fixtureRecord = null;

    public static function canAccess(): bool
    {
        return SportsbookFixturesPage::canAccess();
    }

    public function mount(): void
    {
        abort_if($this->fixture === null, 404);

        $this->record();
    }

    public function record(): SbFixture
    {
        return $this->fixtureRecord ??= SbFixture::query()
            ->with(['competition.sport', 'result'])
            ->findOrFail($this->fixture);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record()->home_team.' v '.$this->record()->away_team;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [SportsbookFixturesPage::getUrl() => 'Fixtures', 'Fixture'];
    }

    public function fixtureInfolist(Schema $schema): Schema
    {
        $fixture = $this->record();
        $result = $fixture->result;
        $state = SportsbookFormat::fixtureState($fixture);

        return $schema
            ->constantState([
                'competition' => ($fixture->competition?->sport?->name ?? 'Sport').' · '.($fixture->competition?->name ?? '—'),
                'kicks_off_at' => $fixture->kicks_off_at,
                'state' => $state,
                'score' => SportsbookFixturesPage::scoreLine($fixture),
                'result_by' => $result === null ? null : ($result->recorded_by_staff ?? ($result->source === 'feed' ? 'Scores feed' : 'Staff')),
            ])
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 5])
                    ->schema([
                        TextEntry::make('competition')
                            ->label('Competition'),
                        TextEntry::make('kicks_off_at')
                            ->label('Kick-off')
                            ->dateTime('D d M Y, H:i'),
                        TextEntry::make('state')
                            ->badge()
                            ->color(fn (string $state): string => SportsbookFormat::fixtureStateColor($state))
                            ->formatStateUsing(fn (string $state): string => SportsbookFormat::FIXTURE_STATES[$state] ?? $state),
                        TextEntry::make('score')
                            ->label($result === null ? 'Live score' : 'Result')
                            ->weight('bold')
                            ->placeholder('—'),
                        TextEntry::make('result_by')
                            ->label('Result from')
                            ->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->recordResultAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SbMarket::query()
                ->where('fixture_id', $this->fixture)
                ->with(['selections', 'fixture'])
                ->orderByRaw("case type when 'h2h' then 0 when 'totals' then 1 when 'spreads' then 2 else 3 end")
                ->orderBy('line')
                ->orderBy('id'))
            ->heading('Markets')
            ->columns([
                TextColumn::make('label')
                    ->label('Market')
                    ->weight('bold')
                    ->state(fn (SbMarket $record): string => SportsbookFormat::marketLabel($record))
                    ->description(fn (SbMarket $record): ?string => $record->source === 'derived' ? 'Derived' : null),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'open' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->description(fn (SbMarket $record): ?string => $record->status === 'suspended' ? SportsbookFormat::suspensionReason($record->suspension_reason) : null),
                TextColumn::make('prices')
                    ->label('Prices')
                    ->state(fn (SbMarket $record): string => static::pricesLine($record))
                    ->wrap(),
                TextColumn::make('priced_at')
                    ->label('Priced')
                    ->since()
                    ->dateTimeTooltip('d M Y, H:i:s')
                    ->placeholder('Never'),
            ])
            ->recordActions([
                $this->suspendMarketAction(),
                $this->reopenMarketAction(),
                $this->overridePricesAction(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No markets')
            ->emptyStateDescription('The feed has not priced this match yet.');
    }

    /**
     * "Home 2.10 · Draw 3.40 · Away 1.95 (override, feed 2.05)".
     */
    public static function pricesLine(SbMarket $market): string
    {
        return $market->selections
            ->map(fn (SbSelection $selection): string => $selection->name.' '.($selection->effectivePrice() ?? '—')
                .($selection->override_price !== null ? ' (override, feed '.($selection->feed_price ?? '—').')' : ''))
            ->implode(' · ');
    }

    protected function suspendMarketAction(): Action
    {
        return Action::make('suspendMarket')
            ->label('Suspend')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (SbMarket $record): bool => static::canTrade() && $record->status === 'open')
            ->modalHeading(fn (SbMarket $record): string => 'Suspend '.SportsbookFormat::marketLabel($record))
            ->modalDescription('Players cannot bet on a suspended market. Bets already placed stand.')
            ->modalSubmitActionLabel('Suspend market')
            ->fillForm(fn (): array => static::newSiteRequest())
            ->schema([
                static::requestIdField(),
                Textarea::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (SbMarket $record, array $data, Action $action): void {
                abort_unless(static::canTrade(), 403);

                $result = $this->sendSiteWrite($action, $data, 'POST', "markets/{$record->id}/suspend", ['reason' => trim($data['reason'])], 'SbMarket', $record->id, 'suspend', 'Could not suspend the market', ['reason' => 'reason']);

                if ($result !== null) {
                    Notification::make()->title(SportsbookFormat::marketLabel($record).' suspended')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function reopenMarketAction(): Action
    {
        return Action::make('reopenMarket')
            ->label('Reopen')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (SbMarket $record): bool => static::canTrade() && $record->status === 'suspended')
            ->requiresConfirmation()
            ->modalHeading(fn (SbMarket $record): string => 'Reopen '.SportsbookFormat::marketLabel($record).'?')
            ->modalDescription('The site reopens it only with a fresh price, and after kick-off only while it trades in play.')
            ->modalSubmitActionLabel('Reopen market')
            ->fillForm(fn (): array => static::newSiteRequest())
            ->schema([static::requestIdField()])
            ->action(function (SbMarket $record, array $data, Action $action): void {
                abort_unless(static::canTrade(), 403);

                $result = $this->sendSiteWrite($action, $data, 'POST', "markets/{$record->id}/reopen", [], 'SbMarket', $record->id, 'reopen', 'Could not reopen the market');

                if ($result !== null) {
                    Notification::make()->title(SportsbookFormat::marketLabel($record).' reopened')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function overridePricesAction(): Action
    {
        return Action::make('overridePrices')
            ->label('Prices')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->visible(fn (): bool => static::canTrade())
            ->modalHeading(fn (SbMarket $record): string => 'Override prices: '.SportsbookFormat::marketLabel($record))
            ->modalDescription(self::PRICES_HELP)
            ->modalSubmitActionLabel('Save prices')
            ->fillForm(fn (SbMarket $record): array => static::newSiteRequest([
                'prices' => $record->selections->mapWithKeys(fn (SbSelection $selection): array => [$selection->id => $selection->override_price])->all(),
            ]))
            ->schema(fn (SbMarket $record): array => [
                static::requestIdField(),
                ...$record->selections->map(fn (SbSelection $selection): TextInput => TextInput::make("prices.{$selection->id}")
                    ->label($selection->name)
                    ->placeholder($selection->feed_price === null ? 'No feed price' : 'Feed '.$selection->feed_price)
                    ->rules(['nullable', 'numeric', 'gt:1', 'regex:/^\d{1,5}(\.\d{1,2})?$/'])
                    ->validationMessages([
                        'gt' => 'A price must be above 1.00.',
                        'regex' => 'Use up to two decimals, e.g. 2.10.',
                    ]))->all(),
                Textarea::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (SbMarket $record, array $data, Action $action): void {
                abort_unless(static::canTrade(), 403);

                $prices = $record->selections
                    ->mapWithKeys(fn (SbSelection $selection): array => [
                        (string) $selection->id => filled($data['prices'][$selection->id] ?? null)
                            ? number_format((float) $data['prices'][$selection->id], 2, '.', '')
                            : null,
                    ])
                    ->all();

                $result = $this->sendSiteWrite($action, $data, 'POST', "markets/{$record->id}/prices", ['prices' => (object) $prices, 'reason' => trim($data['reason'])], 'SbMarket', $record->id, 'prices', 'Could not save the prices', ['reason' => 'reason']);

                if ($result !== null) {
                    Notification::make()->title('Prices saved')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function recordResultAction(): Action
    {
        return Action::make('recordResult')
            ->label(fn (): string => $this->record()->result === null ? 'Record result' : 'Correct result')
            ->icon('heroicon-o-flag')
            ->visible(fn (): bool => static::canSettle()
                && $this->record()->status !== 'cancelled'
                && $this->record()->kicks_off_at->isPast())
            ->modalHeading(fn (): string => ($this->record()->result === null ? 'Record the result of ' : 'Correct the result of ').$this->getTitle())
            ->modalDescription(fn (): string => $this->record()->result === null
                ? 'Bets on this match settle as soon as the result is recorded.'
                : self::CORRECTION_WARNING)
            ->modalSubmitActionLabel(fn (): string => $this->record()->result === null ? 'Record result' : 'Correct result')
            ->fillForm(fn (): array => static::newSiteRequest(['is_void' => false]))
            ->schema([
                static::requestIdField(),
                Toggle::make('is_void')
                    ->label('Void the match')
                    ->helperText('Every leg on it is voided and stakes come back on those legs.')
                    ->live(),
                TextInput::make('home_score')
                    ->label(fn (): string => $this->record()->home_team)
                    ->integer()
                    ->minValue(0)
                    ->required(fn (Get $get): bool => ! $get('is_void'))
                    ->hidden(fn (Get $get): bool => (bool) $get('is_void')),
                TextInput::make('away_score')
                    ->label(fn (): string => $this->record()->away_team)
                    ->integer()
                    ->minValue(0)
                    ->required(fn (Get $get): bool => ! $get('is_void'))
                    ->hidden(fn (Get $get): bool => (bool) $get('is_void')),
                TextInput::make('half_time_home_score')
                    ->label(fn (): string => 'Half time: '.$this->record()->home_team)
                    ->integer()
                    ->minValue(0)
                    ->requiredWith('half_time_away_score')
                    ->hidden(fn (Get $get): bool => (bool) $get('is_void')),
                TextInput::make('half_time_away_score')
                    ->label(fn (): string => 'Half time: '.$this->record()->away_team)
                    ->integer()
                    ->minValue(0)
                    ->requiredWith('half_time_home_score')
                    ->hidden(fn (Get $get): bool => (bool) $get('is_void')),
                Textarea::make('note')
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canSettle(), 403);

                $fixture = $this->record();
                $payload = static::resultPayload($data);
                $event = $fixture->result === null ? 'result' : 'result_correction';

                $result = $this->sendSiteWrite($action, $data, 'POST', "fixtures/{$fixture->id}/result", $payload, 'SbFixture', $fixture->id, $event, 'Could not record the result', self::RESULT_FIELDS);

                if ($result !== null) {
                    Notification::make()
                        ->title($payload['is_void'] ?? false ? 'Match voided' : 'Result recorded')
                        ->body(($result->data['legs_decided'] ?? 0).' legs decided, '.($result->data['bets_settled'] ?? 0).' bets settled.')
                        ->success()
                        ->send();
                    $this->afterSiteWrite();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resultPayload(array $data): array
    {
        if ($data['is_void'] ?? false) {
            return array_filter(['is_void' => true, 'note' => filled($data['note'] ?? null) ? trim($data['note']) : null], fn ($value): bool => $value !== null);
        }

        $payload = [
            'home_score' => (int) $data['home_score'],
            'away_score' => (int) $data['away_score'],
        ];

        if (filled($data['half_time_home_score'] ?? null) && filled($data['half_time_away_score'] ?? null)) {
            $payload['half_time_home_score'] = (int) $data['half_time_home_score'];
            $payload['half_time_away_score'] = (int) $data['half_time_away_score'];
        }

        if (filled($data['note'] ?? null)) {
            $payload['note'] = trim($data['note']);
        }

        return $payload;
    }

    protected function afterSiteWrite(): void
    {
        $this->fixtureRecord = null;
        $this->flushCachedTableRecords();
    }
}
