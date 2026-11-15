<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesSiteWrites;
use App\Exceptions\SiteBackofficeException;
use App\Models\Site\SbJackpot;
use App\Models\Site\SbJackpotFixture;
use App\Services\SiteBackofficeClient;
use App\Support\SportsbookFormat;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
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
 * One Kadi site jackpot: its terms, prize tiers (winners and each prize, a
 * preview until every match is decided), matches and entries. Traders edit a
 * draft; `sportsbook.jackpots` publishes it or cancels it, which refunds every
 * entry through the site's outbox.
 */
class SportsbookJackpotDetailPage extends Page implements HasTable
{
    use HandlesSiteWrites;
    use InteractsWithTable;

    public const PUBLISH_WARNING = 'Players can enter as soon as it is published, and its matches and prizes can no longer be changed. The site checks the match count, kick-offs and prize tiers first.';

    public const CANCEL_WARNING = 'Cancelling is final. Every entry gets its price back through the Kadi API.';

    /**
     * `sb_jackpot_entries.status` values.
     *
     * @var array<string, string>
     */
    public const ENTRY_STATUSES = [
        'pending' => 'Being checked',
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
        'refunded' => 'Refunded',
        'rejected' => 'Rejected',
    ];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sportsbook/jackpots/detail';

    protected string $view = 'filament.pages.sportsbook-jackpot-detail-page';

    #[Url]
    public ?int $jackpot = null;

    protected ?SbJackpot $jackpotRecord = null;

    public static function canAccess(): bool
    {
        return SportsbookJackpotsPage::canAccess();
    }

    public function mount(): void
    {
        abort_if($this->jackpot === null, 404);

        $this->record();
    }

    public function record(): SbJackpot
    {
        return $this->jackpotRecord ??= SbJackpot::query()->findOrFail($this->jackpot);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record()->name;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [SportsbookJackpotsPage::getUrl() => 'Jackpots', 'Jackpot'];
    }

    /**
     * Prize tiers with winners and each winner's prize, from the site (`GET jackpots/{id}/tiers`).
     * Falls back to the stored tiers when the site cannot be reached.
     *
     * @return array{tiers: list<array<string, mixed>>, live: bool}
     */
    public function tiers(): array
    {
        $jackpot = $this->record();

        if ($jackpot->status !== 'draft') {
            try {
                $tiers = app(SiteBackofficeClient::class)->get("jackpots/{$jackpot->id}/tiers")->data['tiers'] ?? null;

                if (is_array($tiers)) {
                    return ['tiers' => array_values($tiers), 'live' => true];
                }
            } catch (SiteBackofficeException) {
                // Shown from the stored tiers below.
            }
        }

        return ['tiers' => array_values($jackpot->prize_tiers ?? []), 'live' => false];
    }

    public function jackpotInfolist(Schema $schema): Schema
    {
        $jackpot = $this->record();
        $tiers = $this->tiers();
        $entries = $jackpot->entries()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return $schema
            ->constantState([
                'status' => $jackpot->status,
                'entry_price' => SportsbookFormat::cents($jackpot->entry_price),
                'prizes' => SportsbookFormat::cents(SportsbookJackpotsPage::totalPrizes($jackpot)),
                'void_limit' => $jackpot->void_limit,
                'published_at' => $jackpot->published_at,
                'cancel_reason' => $jackpot->cancel_reason,
                'entries' => collect(self::ENTRY_STATUSES)
                    ->filter(fn (string $label, string $status): bool => ($entries[$status] ?? 0) > 0)
                    ->map(fn (string $label, string $status): string => $label.': '.$entries[$status])
                    ->implode(' · ') ?: 'None yet',
                'tiers' => collect($tiers['tiers'])->map(fn (array $tier): array => [
                    'correct' => ($tier['correct'] ?? '?').' right',
                    'pool' => SportsbookFormat::cents($tier['pool'] ?? 0),
                    'winners' => $tiers['live'] ? (string) ($tier['winners'] ?? 0) : '—',
                    'each' => $tiers['live'] ? SportsbookFormat::cents($tier['each'] ?? 0) : '—',
                ])->all(),
            ])
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?string $state): string => SportsbookJackpotsPage::statusColor($state))
                            ->formatStateUsing(fn (?string $state): string => SportsbookJackpotsPage::STATUSES[$state] ?? ucfirst((string) $state))
                            ->helperText($jackpot->cancel_reason === null ? null : 'Reason: '.$jackpot->cancel_reason),
                        TextEntry::make('entry_price')->label('Entry price')->weight('bold'),
                        TextEntry::make('prizes')->label('Total prizes'),
                        TextEntry::make('void_limit')->label('Void limit'),
                        TextEntry::make('published_at')->label('Published')->dateTime('d M Y, H:i')->placeholder('—'),
                        TextEntry::make('entries')->label('Entries')->columnSpan(['md' => 3]),
                    ]),
                Section::make('Prize tiers')
                    ->description($tiers['live'] && $jackpot->status === 'open' ? 'A preview until every match is decided.' : null)
                    ->schema([
                        RepeatableEntry::make('tiers')
                            ->hiddenLabel()
                            ->table([
                                RepeatableEntry\TableColumn::make('Matches right'),
                                RepeatableEntry\TableColumn::make('Pool'),
                                RepeatableEntry\TableColumn::make('Winners'),
                                RepeatableEntry\TableColumn::make('Each'),
                            ])
                            ->schema([
                                TextEntry::make('correct'),
                                TextEntry::make('pool'),
                                TextEntry::make('winners'),
                                TextEntry::make('each'),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->editDraftAction(),
            $this->publishAction(),
            $this->cancelAction(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SbJackpotFixture::query()->where('jackpot_id', $this->jackpot)->orderBy('position'))
            ->heading('Matches')
            ->columns([
                TextColumn::make('position')
                    ->label('#'),
                TextColumn::make('fixture_name')
                    ->label('Match')
                    ->weight('bold')
                    ->url(fn (SbJackpotFixture $record): string => SportsbookFixtureDetailPage::getUrl(['fixture' => $record->fixture_id])),
                TextColumn::make('kicks_off_at')
                    ->label('Kick-off')
                    ->dateTime('D d M, H:i'),
                TextColumn::make('outcome')
                    ->label('Outcome')
                    ->state(fn (SbJackpotFixture $record): ?string => $record->is_void ? 'Void' : $record->outcome)
                    ->description(fn (SbJackpotFixture $record): ?string => $record->void_reason)
                    ->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading('No matches');
    }

    protected function editDraftAction(): Action
    {
        return Action::make('editDraft')
            ->label('Edit draft')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => static::canTrade() && $this->record()->status === 'draft')
            ->modalHeading('Edit draft')
            ->modalSubmitActionLabel('Save draft')
            ->fillForm(function (): array {
                $jackpot = $this->record();

                return static::newSiteRequest([
                    'name' => $jackpot->name,
                    'entry_price' => $jackpot->entry_price / 100,
                    'void_limit' => $jackpot->void_limit,
                    'prize_tiers' => collect($jackpot->prize_tiers ?? [])->map(fn (array $tier): array => ['correct' => $tier['correct'], 'pool' => $tier['pool'] / 100])->all(),
                    'fixture_ids' => $jackpot->fixtures()->orderBy('position')->pluck('fixture_id')->all(),
                ]);
            })
            ->schema(fn (): array => SportsbookJackpotsPage::draftFormFields($this->record()->fixtures()->pluck('fixture_id')->all()))
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canTrade(), 403);

                $jackpot = $this->record();
                $result = $this->sendSiteWrite($action, $data, 'PUT', "jackpots/{$jackpot->id}", SportsbookJackpotsPage::draftPayload($data), 'SbJackpot', $jackpot->id, 'draft_update', 'Could not save the draft', SportsbookJackpotsPage::FORM_FIELDS);

                if ($result !== null) {
                    Notification::make()->title('Draft saved')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function publishAction(): Action
    {
        return Action::make('publishJackpot')
            ->label('Publish')
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->visible(fn (): bool => SportsbookJackpotsPage::canRunJackpots() && $this->record()->status === 'draft')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Publish '.$this->record()->name.'?')
            ->modalDescription(self::PUBLISH_WARNING)
            ->modalSubmitActionLabel('Publish')
            ->fillForm(fn (): array => static::newSiteRequest())
            ->schema([static::requestIdField()])
            ->action(function (array $data, Action $action): void {
                abort_unless(SportsbookJackpotsPage::canRunJackpots(), 403);

                $jackpot = $this->record();
                $result = $this->sendSiteWrite($action, $data, 'POST', "jackpots/{$jackpot->id}/publish", [], 'SbJackpot', $jackpot->id, 'publish', 'Could not publish the jackpot');

                if ($result !== null) {
                    Notification::make()->title($jackpot->name.' published')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancelJackpot')
            ->label('Cancel jackpot')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => SportsbookJackpotsPage::canRunJackpots() && in_array($this->record()->status, ['draft', 'open'], true))
            ->modalHeading(fn (): string => 'Cancel '.$this->record()->name.'?')
            ->modalDescription(self::CANCEL_WARNING)
            ->modalSubmitActionLabel('Cancel jackpot')
            ->fillForm(fn (): array => static::newSiteRequest())
            ->schema([
                static::requestIdField(),
                Textarea::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(SportsbookJackpotsPage::canRunJackpots(), 403);

                $jackpot = $this->record();
                $result = $this->sendSiteWrite($action, $data, 'POST', "jackpots/{$jackpot->id}/cancel", ['reason' => trim($data['reason'])], 'SbJackpot', $jackpot->id, 'cancel', 'Could not cancel the jackpot', ['reason' => 'reason']);

                if ($result !== null) {
                    Notification::make()->title($jackpot->name.' cancelled')->body('Every entry is refunded through the Kadi API.')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function afterSiteWrite(): void
    {
        $this->jackpotRecord = null;
        $this->flushCachedTableRecords();
    }
}
