<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesOneShotApiWrites;
use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\Format;
use App\Support\StaffIdentity;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * One Kadi API wager with its settlements and ledger entries
 * (`GET /wagers/{id}/detail`). Finance (`wagers.void`) voids an open wager of a
 * casino game, refunding its stake. Sportsbook and prediction wagers are never
 * voided here: the Kadi site and the market engine own their settlement, so a
 * void here would leave their bets open and collide with their next settlement.
 */
class GameWagerDetailPage extends Page
{
    use HandlesOneShotApiWrites;

    /**
     * Products whose wagers are settled by another system, never voided from GMS directly.
     *
     * @var list<string>
     */
    public const OWNED_ELSEWHERE_PREFIXES = ['sportsbook', 'predict_'];

    public const VOID_WARNING = 'The stake goes back to the player. Voiding cannot be undone.';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'games/wagers/detail';

    protected string $view = 'filament.pages.game-wager-detail-page';

    #[Url]
    public ?int $wager = null;

    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $details = [];

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return GamesPage::canAccess();
    }

    public static function canVoid(): bool
    {
        return auth()->user()?->hasPermissionTo('wagers.void') ?? false;
    }

    /**
     * Whether another system (the Kadi site, the prediction engine) settles this product's wagers.
     */
    public static function ownedElsewhere(?string $product): bool
    {
        foreach (self::OWNED_ELSEWHERE_PREFIXES as $prefix) {
            if (str_starts_with((string) $product, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function mount(): void
    {
        abort_if($this->wager === null, 404);

        $this->loadWager();
    }

    protected function loadWager(): void
    {
        try {
            $this->details = app(GameApiService::class)->getWagerDetail((int) $this->wager);
            $this->apiError = false;
        } catch (GameApiException $e) {
            abort_if($e->statusCode === 404, 404);
            Log::warning('Wager detail failed', ['wager' => $this->wager, 'error' => $e->getMessage()]);
            $this->apiError = true;
        }
    }

    protected function afterOneShotWrite(): void
    {
        $this->loadWager();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Wager '.($this->details['wager']['reference'] ?? $this->wager);
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [GameWagersPage::getUrl() => 'Wagers', 'Wager'];
    }

    public function wagerInfolist(Schema $schema): Schema
    {
        $wager = $this->details['wager'] ?? [];
        $ownedElsewhere = static::ownedElsewhere($wager['product'] ?? null);

        return $schema
            ->constantState([
                'status' => $wager['status'] ?? null,
                'product' => ($wager['game'] ?? '').' · '.($wager['product'] ?? ''),
                'customer' => isset($wager['customer_id']) ? '#'.$wager['customer_id'] : null,
                'stake' => ($wager['funding'] ?? null) === 'free_bet' ? Format::money($wager['free_bet_amount'] ?? 0).' (free bet)' : Format::money($wager['stake'] ?? 0),
                'payout' => Format::money($wager['payout'] ?? 0).(($wager['bonus'] ?? 0) > 0 ? ' + bonus '.Format::money($wager['bonus']) : ''),
                'tax' => Format::money($wager['tax'] ?? 0),
                'placed_at' => Format::dateTime($wager['placed_at'] ?? null),
                'settled_at' => Format::dateTime($wager['settled_at'] ?? null),
                'version' => (string) ($wager['settlement_version'] ?? 0),
                'round' => $wager['round_reference'] ?? null,
                'settlements' => collect($this->details['settlements'] ?? [])->map(fn (array $settlement): string => 'v'.($settlement['version'] ?? '?').' '.($settlement['outcome'] ?? '?')
                    .' · paid '.Format::money($settlement['payout'] ?? 0).' · '.($settlement['status'] ?? '')
                    .(filled($settlement['reason'] ?? null) ? ' · '.$settlement['reason'] : '')
                    .' · '.Format::dateTime($settlement['created_at'] ?? null))->values()->all(),
                'ledger' => collect($this->details['ledger_entries'] ?? [])->map(fn (array $entry): string => ($entry['entry_type'] ?? '?').' · '.($entry['wallet_type'] ?? '')
                    .' · '.(($entry['debit'] ?? 0) > 0 ? '−'.Format::money($entry['debit']) : '+'.Format::money($entry['credit'] ?? 0))
                    .' → '.Format::money($entry['balance_after'] ?? 0).' · '.Format::dateTime($entry['created_at'] ?? null))->values()->all(),
                'void_note' => $ownedElsewhere && ($wager['status'] ?? null) === 'open'
                    ? (str_starts_with((string) ($wager['product'] ?? ''), 'sportsbook')
                        ? 'This wager belongs to a Kadi site sports bet: cancel the bet or void its leg on the site\'s bet page (Sportsbook › Bets), never here.'
                        : 'This wager belongs to a prediction market: void the market (Predictions › Markets), never the wager here.')
                    : null,
            ])
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        TextEntry::make('status')->badge()
                            ->color(fn (?string $state): string => GameWagersPage::statusColor($state))
                            ->formatStateUsing(fn (?string $state): string => GameWagersPage::STATUSES[$state] ?? ucfirst((string) $state)),
                        TextEntry::make('product'),
                        TextEntry::make('customer'),
                        TextEntry::make('stake')->weight('bold'),
                        TextEntry::make('payout'),
                        TextEntry::make('tax')->label('Winnings tax'),
                        TextEntry::make('placed_at')->label('Placed'),
                        TextEntry::make('settled_at')->label('Settled'),
                        TextEntry::make('version')->label('Settlement version'),
                        TextEntry::make('round')->placeholder('—'),
                        TextEntry::make('void_note')->hiddenLabel()->color('warning')->columnSpanFull()->visible(filled($ownedElsewhere && ($wager['status'] ?? null) === 'open')),
                    ]),
                Section::make('Settlements')->schema([TextEntry::make('settlements')->hiddenLabel()->listWithLineBreaks()->placeholder('Not settled yet.')]),
                Section::make('Ledger entries')->schema([TextEntry::make('ledger')->hiddenLabel()->listWithLineBreaks()->placeholder('—')]),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('voidWager')
                ->label('Void wager')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => static::canVoid()
                    && ($this->details['wager']['status'] ?? null) === 'open'
                    && ! static::ownedElsewhere($this->details['wager']['product'] ?? null))
                ->modalHeading(fn (): string => 'Void '.$this->getTitle().'?')
                ->modalDescription(self::VOID_WARNING)
                ->modalSubmitActionLabel('Void wager')
                ->schema([Textarea::make('reason')->required()->maxLength(255)->rows(2)])
                ->action(function (array $data, Action $action): void {
                    abort_unless(static::canVoid() && ! static::ownedElsewhere($this->details['wager']['product'] ?? null), 403);

                    $id = (int) $this->wager;
                    $payload = ['reason' => trim($data['reason']), 'admin_user' => StaffIdentity::adminUser(auth()->user())];
                    $result = $this->sendOneShotWrite($action, fn (): array => app(GameApiService::class)->voidWager($id, $payload), 'Wager', $id, 'void', $payload, 'Could not void the wager', ['reason' => 'reason']);

                    if ($result !== null) {
                        Notification::make()->title('Wager voided')->body('The stake is back with the player.')->success()->send();
                        $this->afterOneShotWrite();
                    }
                }),
        ];
    }
}
