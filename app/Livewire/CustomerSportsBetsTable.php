<?php

namespace App\Livewire;

use App\Filament\Pages\SportsbookBetDetailPage;
use App\Filament\Pages\SportsbookBetsPage;
use App\Models\Site\SbBet;
use App\Support\SportsbookFormat;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * A player's sports bets on the Kadi site, found by their Kadi API customer id
 * (`users.linked_id`). Read-only on the customer page; staff with
 * `sportsbook.view` can open a bet.
 */
class CustomerSportsBetsTable extends TableWidget
{
    public int $customerId;

    protected int|string|array $columnSpan = 'full';

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(null)
            ->query(fn (): Builder => SbBet::query()
                ->whereHas('user', fn (Builder $query): Builder => $query->where('linked_id', $this->customerId))
                ->withCount('legs')
                ->latest('placed_at'))
            ->columns([
                TextColumn::make('placed_at')->label('Placed')->dateTime('d M Y, H:i'),
                TextColumn::make('reference')->label('Bet')->fontFamily('mono')
                    ->description(fn (SbBet $record): string => ucfirst((string) $record->type).' · '.$record->legs_count.' legs'.($record->kadiProduct() === 'sportsbook_live' ? ' · in play' : '')),
                TextColumn::make('stake')->alignEnd()->formatStateUsing(fn (?int $state): string => SportsbookFormat::cents($state)),
                TextColumn::make('total_odds')->label('Odds')->alignEnd(),
                TextColumn::make('payout')->alignEnd()->formatStateUsing(fn (?int $state): string => SportsbookFormat::cents($state))->placeholder('—'),
                TextColumn::make('status')->badge()
                    ->color(fn (?string $state): string => SportsbookBetsPage::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => SportsbookBetsPage::STATUSES[$state] ?? ucfirst((string) $state)),
            ])
            ->recordUrl(fn (SbBet $record): ?string => SportsbookBetDetailPage::canAccess() ? SportsbookBetDetailPage::getUrl(['bet' => $record->id]) : null)
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No sports bets');
    }
}
