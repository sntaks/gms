<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesSiteWrites;
use App\Models\Site\SbCompetition;
use App\Models\Site\SbSport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The Kadi site's competitions and how each is run: whether its odds are synced
 * from the feed, whether its bets settle from the scores feed, and its order on
 * the site. Traders (`sportsbook.trade`) change these through the signed API.
 */
class SportsbookCompetitionsPage extends Page implements HasTable
{
    use HandlesSiteWrites;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationLabel = 'Competitions';

    protected static string|UnitEnum|null $navigationGroup = '⚽ Sportsbook';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'sportsbook/competitions';

    protected ?string $heading = 'Competitions';

    protected ?string $subheading = 'Leagues and tournaments on the Kadi site, with their odds sync and auto-settle settings.';

    protected string $view = 'filament.pages.sportsbook-competitions-page';

    public static function canAccess(): bool
    {
        return SportsbookFixturesPage::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SbCompetition::query()->with('sport')->orderBy('sort_order')->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->description(fn (SbCompetition $record): string => $record->sport?->name ?? '—')
                    ->searchable(),
                TextColumn::make('odds_api_key')
                    ->label('Feed key')
                    ->fontFamily('mono')
                    ->toggleable(),
                IconColumn::make('is_synced')
                    ->label('Odds synced')
                    ->boolean(),
                IconColumn::make('auto_settle')
                    ->label('Auto-settle')
                    ->boolean(),
                IconColumn::make('is_active_in_feed')
                    ->label('In feed')
                    ->boolean()
                    ->tooltip('Whether the Odds API currently lists this competition.'),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->alignEnd()
                    ->numeric(),
            ])
            ->filters([
                SelectFilter::make('sport_id')
                    ->label('Sport')
                    ->options(fn (): array => SbSport::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
            ])
            ->recordActions([$this->editSettingsAction()])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No competitions')
            ->striped();
    }

    protected function editSettingsAction(): Action
    {
        return Action::make('editSettings')
            ->label('Settings')
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->visible(fn (): bool => static::canTrade())
            ->modalHeading(fn (SbCompetition $record): string => $record->name)
            ->modalSubmitActionLabel('Save settings')
            ->fillForm(fn (SbCompetition $record): array => static::newSiteRequest([
                'is_synced' => $record->is_synced,
                'auto_settle' => $record->auto_settle,
                'sort_order' => $record->sort_order,
            ]))
            ->schema([
                static::requestIdField(),
                Toggle::make('is_synced')
                    ->label('Sync odds from the feed')
                    ->helperText('Off stops new odds for its matches; open markets go stale and suspend.'),
                Toggle::make('auto_settle')
                    ->label('Settle from the scores feed')
                    ->helperText('Off leaves every result to staff.'),
                TextInput::make('sort_order')
                    ->label('Order on the site')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(10000)
                    ->required(),
            ])
            ->action(function (SbCompetition $record, array $data, Action $action): void {
                abort_unless(static::canTrade(), 403);

                $payload = [
                    'is_synced' => (bool) $data['is_synced'],
                    'auto_settle' => (bool) $data['auto_settle'],
                    'sort_order' => (int) $data['sort_order'],
                ];

                $result = $this->sendSiteWrite($action, $data, 'PATCH', "competitions/{$record->id}", $payload, 'SbCompetition', $record->id, 'settings', 'Could not save the settings', ['sort_order' => 'sort_order']);

                if ($result !== null) {
                    Notification::make()->title($record->name.' settings saved')->success()->send();
                    $this->afterSiteWrite();
                }
            });
    }

    protected function afterSiteWrite(): void
    {
        $this->flushCachedTableRecords();
    }
}
