<?php

namespace App\Filament\Pages;

use App\Models\Site\SiteActivity;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The Kadi site's sportsbook activity log: trading (`odds`) and settlement
 * (`settlement`) changes, by the system or by GMS staff, who are named from
 * `properties.staff`.
 */
class SportsbookActivityPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const LOGS = [
        SiteActivity::LOG_ODDS => 'Trading',
        SiteActivity::LOG_SETTLEMENT => 'Settlement',
    ];

    /**
     * Properties shown as details; `staff` and `source` are shown as the actor instead.
     *
     * @var list<string>
     */
    public const HIDDEN_PROPERTIES = ['staff', 'source'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Activity';

    protected static string|UnitEnum|null $navigationGroup = '⚽ Sportsbook';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'sportsbook/activity';

    protected ?string $heading = 'Sportsbook activity';

    protected ?string $subheading = 'Trading and settlement changes on the Kadi site, newest first.';

    protected string $view = 'filament.pages.sportsbook-activity-page';

    public static function canAccess(): bool
    {
        return SportsbookFixturesPage::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SiteActivity::query()->sportsbook()->latest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M, H:i:s'),
                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->color(fn (?string $state): string => $state === SiteActivity::LOG_SETTLEMENT ? 'warning' : 'info')
                    ->formatStateUsing(fn (?string $state): string => self::LOGS[$state] ?? (string) $state),
                TextColumn::make('description')
                    ->label('What')
                    ->weight('bold')
                    ->description(fn (SiteActivity $record): ?string => static::details($record))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('actor')
                    ->label('By')
                    ->state(fn (SiteActivity $record): string => $record->staffLabel() ?? 'System'),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(self::LOGS),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No activity')
            ->striped();
    }

    /**
     * The entry's properties as "key: value · key: value", without the actor.
     */
    public static function details(SiteActivity $activity): ?string
    {
        $details = collect($activity->properties ?? [])
            ->except(self::HIDDEN_PROPERTIES)
            ->map(fn (mixed $value, string $key): string => str_replace('_', ' ', $key).': '.static::detailValue($value))
            ->implode(' · ');

        return $details === '' ? null : $details;
    }

    protected static function detailValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
