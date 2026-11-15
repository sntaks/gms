<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesSiteWrites;
use App\Models\Site\SbBet;
use App\Models\Site\SbBetLeg;
use App\Support\SportsbookFormat;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * One Kadi site sports bet and its legs. Finance (`sportsbook.settle`) voids a
 * leg or cancels an open bet through the site's signed back-office API; the
 * site re-settles or refunds the Kadi API wager through its outbox.
 */
class SportsbookBetDetailPage extends Page implements HasTable
{
    use HandlesSiteWrites;
    use InteractsWithTable;

    public const VOID_LEG_WARNING = 'The leg then counts as odds 1.00 and the bet settles again, reopening it first if it had already settled. The leg stays void even if the match result is corrected later.';

    public const CANCEL_WARNING = 'The bet is cancelled and its stake refunded to the player through the Kadi API.';

    /**
     * `sb_bet_legs.status` values.
     *
     * @var array<string, string>
     */
    public const LEG_STATUSES = [
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
        'void' => 'Void',
    ];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sportsbook/bets/detail';

    protected string $view = 'filament.pages.sportsbook-bet-detail-page';

    #[Url]
    public ?int $bet = null;

    protected ?SbBet $betRecord = null;

    public static function canAccess(): bool
    {
        return SportsbookBetsPage::canAccess();
    }

    public function mount(): void
    {
        abort_if($this->bet === null, 404);

        $this->record();
    }

    public function record(): SbBet
    {
        return $this->betRecord ??= SbBet::query()->with(['user', 'freeBet'])->findOrFail($this->bet);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Bet '.$this->record()->reference;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [SportsbookBetsPage::getUrl() => 'Bets', 'Bet'];
    }

    public function betInfolist(Schema $schema): Schema
    {
        $bet = $this->record();

        return $schema
            ->constantState([
                'status' => $bet->status,
                'player' => $bet->user === null ? 'Closed account' : $bet->user->name.($bet->user->linked_id === null ? '' : ' · Customer #'.$bet->user->linked_id),
                'product' => $bet->kadiProduct() === 'sportsbook_live' ? 'In play' : 'Pre-match',
                'stake' => SportsbookFormat::cents($bet->stake).($bet->free_bet_id !== null ? ' (free bet)' : ''),
                'total_odds' => $bet->total_odds,
                'payout' => $bet->payout === null ? null : SportsbookFormat::cents($bet->payout),
                'potential_payout' => SportsbookFormat::cents($bet->potential_payout),
                'tax' => $bet->tax === null ? null : SportsbookFormat::cents($bet->tax),
                'placed_at' => $bet->placed_at,
                'accept_after' => $bet->accept_after,
                'settled_at' => $bet->settled_at,
                'wager_reference' => $bet->wager_reference,
                'settlement_version' => $bet->settlement_version,
                'odds_change_policy' => $bet->odds_change_policy,
            ])
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?string $state): string => SportsbookBetsPage::statusColor($state))
                            ->formatStateUsing(fn (?string $state): string => SportsbookBetsPage::STATUSES[$state] ?? ucfirst((string) $state))
                            ->helperText($bet->reject_reason === null ? null : 'Reason: '.$bet->reject_reason),
                        TextEntry::make('player'),
                        TextEntry::make('product'),
                        TextEntry::make('stake')->weight('bold'),
                        TextEntry::make('total_odds')->label('Odds'),
                        TextEntry::make('potential_payout')->label('Potential payout'),
                        TextEntry::make('payout')->label('Paid')->placeholder('—'),
                        TextEntry::make('tax')->label('Winnings tax')->placeholder('—'),
                        TextEntry::make('placed_at')->label('Placed')->dateTime('d M Y, H:i:s'),
                        TextEntry::make('accept_after')
                            ->label('Held until')
                            ->dateTime('d M Y, H:i:s')
                            ->placeholder('—')
                            ->helperText('In-play bets wait out the live delay, then are checked again.'),
                        TextEntry::make('settled_at')->label('Settled')->dateTime('d M Y, H:i:s')->placeholder('—'),
                        TextEntry::make('odds_change_policy')->label('Odds changes')->formatStateUsing(fn (?string $state): string => match ($state) {
                            'any' => 'Accept any',
                            'higher' => 'Accept higher',
                            'none' => 'Accept none',
                            default => (string) $state,
                        }),
                        TextEntry::make('wager_reference')
                            ->label('Kadi API wager')
                            ->fontFamily('mono')
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpan(['md' => 3]),
                        TextEntry::make('settlement_version')->label('Settlement version'),
                    ]),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->cancelBetAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SbBetLeg::query()->where('bet_id', $this->bet)->orderBy('kicks_off_at')->orderBy('id'))
            ->heading('Legs')
            ->columns([
                TextColumn::make('fixture_name')
                    ->label('Match')
                    ->weight('bold')
                    ->description(fn (SbBetLeg $record): string => $record->kicks_off_at->timezone(config('app.timezone'))->format('d M, H:i'))
                    ->url(fn (SbBetLeg $record): string => SportsbookFixtureDetailPage::getUrl(['fixture' => $record->fixture_id])),
                TextColumn::make('market_name')
                    ->label('Market')
                    ->description(fn (SbBetLeg $record): ?string => $record->in_play ? 'In play at '.($record->score_at_placement ?? '?') : null),
                TextColumn::make('selection_name')
                    ->label('Pick'),
                TextColumn::make('price')
                    ->label('Odds')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => SportsbookBetsPage::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::LEG_STATUSES[$state] ?? ucfirst((string) $state))
                    ->description(fn (SbBetLeg $record): ?string => $record->void_reason),
            ])
            ->recordActions([$this->voidLegAction()])
            ->paginated(false);
    }

    protected function voidLegAction(): Action
    {
        return Action::make('voidLeg')
            ->label('Void leg')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (SbBetLeg $record): bool => static::canSettle()
                && $record->status !== 'void'
                && in_array($this->record()->status, ['open', 'won', 'lost'], true))
            ->modalHeading(fn (SbBetLeg $record): string => 'Void '.$record->selection_name.' in '.$record->fixture_name.'?')
            ->modalDescription(self::VOID_LEG_WARNING)
            ->modalSubmitActionLabel('Void leg')
            ->fillForm(fn (): array => static::newSiteRequest())
            ->schema([
                static::requestIdField(),
                Textarea::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (SbBetLeg $record, array $data, Action $action): void {
                abort_unless(static::canSettle(), 403);

                $result = $this->sendSiteWrite($action, $data, 'POST', "bet-legs/{$record->id}/void", ['reason' => trim($data['reason'])], 'SbBetLeg', $record->id, 'void', 'Could not void the leg', ['reason' => 'reason']);

                if ($result !== null) {
                    $status = $result->data['bet']['status'] ?? null;

                    Notification::make()
                        ->title('Leg voided')
                        ->body($status === null ? null : 'The bet is now '.strtolower(SportsbookBetsPage::STATUSES[$status] ?? $status).'.')
                        ->success()
                        ->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function cancelBetAction(): Action
    {
        return Action::make('cancelBet')
            ->label('Cancel bet')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => static::canSettle() && $this->record()->status === 'open')
            ->modalHeading(fn (): string => 'Cancel bet '.$this->record()->reference.'?')
            ->modalDescription(self::CANCEL_WARNING)
            ->modalSubmitActionLabel('Cancel bet')
            ->fillForm(fn (): array => static::newSiteRequest())
            ->schema([
                static::requestIdField(),
                Textarea::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canSettle(), 403);

                $bet = $this->record();
                $result = $this->sendSiteWrite($action, $data, 'POST', "bets/{$bet->id}/cancel", ['reason' => trim($data['reason'])], 'SbBet', $bet->id, 'cancel', 'Could not cancel the bet', ['reason' => 'reason']);

                if ($result !== null) {
                    Notification::make()->title('Bet '.$bet->reference.' cancelled')->body('The stake is refunded through the Kadi API.')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function afterSiteWrite(): void
    {
        $this->betRecord = null;
        $this->flushCachedTableRecords();
    }
}
