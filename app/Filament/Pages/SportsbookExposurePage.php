<?php

namespace App\Filament\Pages;

use App\Exceptions\SiteBackofficeException;
use App\Services\SiteBackofficeClient;
use App\Support\SportsbookFormat;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * What the house stands to pay on matches still to play (`GET exposure` on the
 * Kadi site's back-office API): per match, the bets and stakes and the outcome
 * that would cost the most. Largest worst case first.
 */
class SportsbookExposurePage extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Matches to ask for (the site allows 1 to 500).
     */
    public const LIMIT = 100;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Exposure';

    protected static string|UnitEnum|null $navigationGroup = '⚽ Sportsbook';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'sportsbook/exposure';

    protected ?string $heading = 'Exposure';

    protected ?string $subheading = 'Payouts the house would owe on matches still to play, by the costliest outcome.';

    protected string $view = 'filament.pages.sportsbook-exposure-page';

    public ?string $siteError = null;

    public static function canAccess(): bool
    {
        return SportsbookFixturesPage::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->fetchMatches())
            ->columns([
                TextColumn::make('kicks_off_at')
                    ->label('Kick-off')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Carbon::parse($state)->timezone(config('app.timezone'))->format('D d M, H:i')),
                TextColumn::make('match')
                    ->weight('bold')
                    ->description(fn (array $record): ?string => $record['competition'] ?? null)
                    ->url(fn (array $record): ?string => isset($record['fixture_id']) ? SportsbookFixtureDetailPage::getUrl(['fixture' => (int) $record['fixture_id']]) : null),
                TextColumn::make('bets')
                    ->alignEnd()
                    ->numeric(),
                TextColumn::make('stakes')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => SportsbookFormat::cents($state)),
                TextColumn::make('worst_payout')
                    ->label('Worst case')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('danger')
                    ->formatStateUsing(fn ($state): string => SportsbookFormat::cents($state))
                    ->description(fn (array $record): ?string => isset($record['worst_pick']) ? 'if '.$record['worst_pick'] : null),
                TextColumn::make('picks')
                    ->label('By outcome')
                    ->state(fn (array $record): string => collect($record['picks'] ?? [])
                        ->map(fn (array $pick): string => ($pick['pick'] ?? '?').': '.SportsbookFormat::cents($pick['payout'] ?? 0).' on '.($pick['bets'] ?? 0))
                        ->implode(' · '))
                    ->wrap(),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-scale')
            ->emptyStateHeading(fn (): string => $this->siteError === null ? 'No open exposure' : 'Exposure unavailable')
            ->emptyStateDescription(fn (): ?string => $this->siteError);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function fetchMatches(): Collection
    {
        try {
            $data = app(SiteBackofficeClient::class)->get('exposure', ['limit' => self::LIMIT])->data;
            $this->siteError = null;
        } catch (SiteBackofficeException $e) {
            $this->siteError = $e->getMessage();

            return collect();
        }

        return collect($data['matches'] ?? [])
            ->filter(fn ($match): bool => is_array($match) && isset($match['fixture_id']))
            ->mapWithKeys(fn (array $match): array => [(string) $match['fixture_id'] => [...$match, ArrayRecord::getKeyName() => (string) $match['fixture_id']]]);
    }
}
