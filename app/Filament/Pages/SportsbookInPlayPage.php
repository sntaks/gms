<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Widgets\InPlaySummaryWidget;
use App\Models\Site\SbFixture;
use App\Models\Site\SbMarket;
use App\Support\SportsbookFormat;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Matches the Kadi site is trading in play now: score, how fresh the live
 * prices are (by the site's own thresholds), and why markets are suspended.
 * Refreshes itself; open a match to suspend, reopen or override its markets.
 */
class SportsbookInPlayPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'In play';

    protected static string|UnitEnum|null $navigationGroup = '⚽ Sportsbook';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'sportsbook/in-play';

    protected ?string $heading = 'In play';

    protected ?string $subheading = 'Live matches on the Kadi site, with the age of their newest live price.';

    protected string $view = 'filament.pages.sportsbook-in-play-page';

    public static function canAccess(): bool
    {
        return SportsbookFixturesPage::canAccess();
    }

    /**
     * Live matches with the time of their newest feed price (`live_priced_at`, UTC).
     *
     * @return Builder<SbFixture>
     */
    public static function liveFixtures(): Builder
    {
        $query = SbFixture::query()
            ->with(['competition.sport', 'markets:id,fixture_id,status,suspension_reason'])
            ->withMax(['markets as live_priced_at' => fn (Builder $query): Builder => $query->where('source', 'feed')], 'priced_at');

        SportsbookFixturesPage::applyTab($query, 'live');

        return $query->orderBy('kicks_off_at');
    }

    public static function priceAgeSeconds(SbFixture $fixture): ?int
    {
        $pricedAt = $fixture->getAttribute('live_priced_at');

        return $pricedAt === null ? null : (int) max(0, Carbon::parse($pricedAt, 'UTC')->diffInSeconds(now('UTC')));
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [InPlaySummaryWidget::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::liveFixtures())
            ->poll(config('sportsbook.in_play.poll_seconds').'s')
            ->columns([
                TextColumn::make('kicks_off_at')
                    ->label('Kicked off')
                    ->dateTime('H:i')
                    ->description(fn (SbFixture $record): string => (int) $record->kicks_off_at->diffInMinutes(now()).' min ago'),
                TextColumn::make('match')
                    ->state(fn (SbFixture $record): string => $record->home_team.' v '.$record->away_team)
                    ->weight('bold')
                    ->description(fn (SbFixture $record): string => ($record->competition?->sport?->name ?? 'Sport').' · '.($record->competition?->name ?? '—')),
                TextColumn::make('score')
                    ->state(fn (SbFixture $record): ?string => SportsbookFixturesPage::scoreLine($record))
                    ->weight('bold')
                    ->placeholder('No score yet')
                    ->description(fn (SbFixture $record): ?string => $record->score_changed_at === null ? null : 'changed '.$record->score_changed_at->diffForHumans()),
                TextColumn::make('feed')
                    ->label('Live prices')
                    ->badge()
                    ->state(fn (SbFixture $record): string => SportsbookFormat::feedHealth(static::priceAgeSeconds($record)))
                    ->color(fn (string $state): string => SportsbookFormat::feedHealthColor($state))
                    ->formatStateUsing(fn (string $state): string => SportsbookFormat::FEED_HEALTH[$state])
                    ->description(fn (SbFixture $record): ?string => ($age = static::priceAgeSeconds($record)) === null ? null : $age.' s old'),
                TextColumn::make('markets')
                    ->label('Markets')
                    ->state(fn (SbFixture $record): string => static::marketsLine($record->markets))
                    ->wrap(),
            ])
            ->recordUrl(fn (SbFixture $record): string => SportsbookFixtureDetailPage::getUrl(['fixture' => $record->id]))
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-signal')
            ->emptyStateHeading('No live matches')
            ->emptyStateDescription('Nothing is being traded in play right now.');
    }

    /**
     * "3 open · 2 suspended (Score just changed ×2)".
     *
     * @param  Collection<int, SbMarket>  $markets
     */
    public static function marketsLine(Collection $markets): string
    {
        $suspended = $markets->where('status', 'suspended');
        $reasons = $suspended
            ->countBy(fn (SbMarket $market): string => SportsbookFormat::suspensionReason($market->suspension_reason) ?? 'No reason')
            ->map(fn (int $count, string $reason): string => $count > 1 ? "{$reason} ×{$count}" : $reason)
            ->implode(', ');

        return $markets->where('status', 'open')->count().' open · '.$suspended->count().' suspended'.($reasons === '' ? '' : " ({$reasons})");
    }
}
