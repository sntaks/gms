<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesSiteWrites;
use App\Models\Site\SbWagerOp;
use App\Support\SportsbookFormat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The Kadi site's wager outbox (`sb_wager_ops`): each settlement or reversal the
 * site owes the Kadi API, sent in order per wager. Finance (`sportsbook.settle`)
 * retries a failed op after fixing its cause; the site sends it again for the
 * same wager and version, so it never pays twice.
 */
class SportsbookWagerOpsPage extends Page implements HasTable
{
    use HandlesSiteWrites;
    use InteractsWithTable;

    /**
     * A pending op older than this needs a person (kadi config sportsbook.wagers.pending_alert_minutes).
     */
    public const OVERDUE_MINUTES = 10;

    public const RETRY_WARNING = 'Fix the cause first (the error below says what the Kadi API answered). The op is put back in line and sent again for the same wager and version, so the player is never paid twice.';

    public const TABS = [
        'needs_operator' => 'Needs attention',
        'failed' => 'Failed',
        'pending' => 'Pending',
        'sent' => 'Sent',
        'all' => 'All',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Wager ops';

    protected static string|UnitEnum|null $navigationGroup = '⚽ Sportsbook';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'sportsbook/wager-ops';

    protected ?string $heading = 'Wager ops';

    protected ?string $subheading = 'Settlements and reversals the Kadi site sends to the Kadi API. Failed ops, and ops pending over 10 minutes, need a person.';

    protected string $view = 'filament.pages.sportsbook-wager-ops-page';

    #[Url]
    public string $tab = 'needs_operator';

    public static function canAccess(): bool
    {
        return SportsbookFixturesPage::canAccess();
    }

    /**
     * Failed ops, and pending ones older than the alert time. Mirrors the site's WagerOp::needsOperator().
     *
     * @param  Builder<SbWagerOp>  $query
     */
    public static function needsOperator(Builder $query): void
    {
        $query->where(fn (Builder $query): Builder => $query
            ->where('status', 'failed')
            ->orWhere(fn (Builder $query): Builder => $query
                ->where('status', 'pending')
                ->where('created_at', '<', now()->utc()->subMinutes(self::OVERDUE_MINUTES))));
    }

    public static function navigationBadgeCount(): int
    {
        return SbWagerOp::query()->tap(fn (Builder $query) => static::needsOperator($query))->count();
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = static::navigationBadgeCount();
        } catch (\Throwable) {
            // The site's database being unreachable must not break the sidebar.
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'needs_operator';
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

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'failed' => 'danger',
            'pending' => 'warning',
            'sent' => 'success',
            default => 'gray',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $query = SbWagerOp::query()->latest('id');

                match ($this->tab) {
                    'needs_operator' => static::needsOperator($query),
                    'all' => null,
                    default => $query->where('status', $this->tab),
                };

                return $query;
            })
            ->columns([
                TextColumn::make('created_at')
                    ->label('Queued')
                    ->dateTime('d M, H:i:s')
                    ->description(fn (SbWagerOp $record): ?string => $record->created_at?->diffForHumans()),
                TextColumn::make('operation')
                    ->label('Op')
                    ->formatStateUsing(fn (string $state, SbWagerOp $record): string => ucfirst($state).' v'.$record->version)
                    ->description(fn (SbWagerOp $record): string => $record->product),
                TextColumn::make('subject')
                    ->label('For')
                    ->state(fn (SbWagerOp $record): string => ($record->subject_type === 'bet' ? 'Bet' : 'Jackpot entry').' #'.$record->subject_id)
                    ->url(fn (SbWagerOp $record): ?string => $record->subject_type === 'bet' ? SportsbookBetDetailPage::getUrl(['bet' => $record->subject_id]) : null),
                TextColumn::make('wager_reference')
                    ->label('Kadi API wager')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(13)
                    ->tooltip(fn (SbWagerOp $record): string => $record->wager_reference)
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Payout')
                    ->alignEnd()
                    ->state(fn (SbWagerOp $record): ?int => isset($record->payload['payout']) ? (int) $record->payload['payout'] : null)
                    ->formatStateUsing(fn (?int $state): string => SportsbookFormat::cents($state))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->description(fn (SbWagerOp $record): string => $record->attempts.' '.str('attempt')->plural($record->attempts)),
                TextColumn::make('last_error')
                    ->label('Last answer')
                    ->state(fn (SbWagerOp $record): ?string => $record->last_status === null && $record->last_error === null
                        ? null
                        : trim(($record->last_status === null ? '' : 'HTTP '.$record->last_status.' ').($record->last_error ?? '')))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->recordActions([$this->retryAction()])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading(fn (): string => $this->tab === 'needs_operator' ? 'Nothing needs attention' : 'No wager ops')
            ->striped();
    }

    protected function retryAction(): Action
    {
        return Action::make('retryOp')
            ->label('Retry')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (SbWagerOp $record): bool => static::canSettle() && $record->status === 'failed')
            ->requiresConfirmation()
            ->modalHeading(fn (SbWagerOp $record): string => 'Retry '.$record->operation.' v'.$record->version.'?')
            ->modalDescription(self::RETRY_WARNING)
            ->modalSubmitActionLabel('Retry op')
            ->fillForm(fn (): array => static::newSiteRequest())
            ->schema([static::requestIdField()])
            ->action(function (SbWagerOp $record, array $data, Action $action): void {
                abort_unless(static::canSettle(), 403);

                $result = $this->sendSiteWrite($action, $data, 'POST', "wager-ops/{$record->id}/retry", [], 'SbWagerOp', $record->id, 'retry', 'Could not retry the op');

                if ($result !== null) {
                    Notification::make()->title('Op queued again')->body('The site sends it to the Kadi API shortly.')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function afterSiteWrite(): void
    {
        $this->flushCachedTableRecords();
    }
}
