<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Models\User;
use App\Services\SportsbookReconciliation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One Nairobi day of the Kadi site's sportsbook reconciled against the Kadi API
 * (see {@see SportsbookReconciliation}), plus what needs an operator now: failed
 * or overdue wager ops and placements still unknown after 10 minutes.
 * Needs finance-report access ({@see User::canViewFinanceReports()}) and `sportsbook.view`.
 */
class SportsbookReconciliationReport extends Page implements HasTable
{
    use InteractsWithTable;

    public const STATUSES = [
        'match' => 'Matches',
        'differs' => 'Differs',
        'info' => 'Not compared',
    ];

    protected static ?string $cluster = FinanceReportsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::OVERVIEW;

    /**
     * Last in Overview, beside Reconciliation (same sort; pages are discovered alphabetically).
     */
    protected static ?int $navigationSort = 14;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Sportsbook reconciliation';

    protected static ?string $slug = 'sportsbook-reconciliation';

    protected ?string $heading = 'Sportsbook reconciliation';

    protected string $view = 'filament.pages.sportsbook-reconciliation-report';

    #[Url]
    public ?string $date = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $reconciliation = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->canViewFinanceReports() && $user->hasPermissionTo('sportsbook.view');
    }

    public function mount(): void
    {
        $this->date = $this->validDate($this->date);
    }

    /**
     * @return array<string, mixed>
     */
    public function reconciliation(): array
    {
        return $this->reconciliation ??= app(SportsbookReconciliation::class)->forDay((string) $this->date);
    }

    public function getSubheading(): string
    {
        return 'The Kadi site\'s sportsbook against the Kadi API for '.Carbon::parse($this->date)->format('l d M Y').' (Nairobi), by settlements made that day.';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pickDay')
                ->label(fn (): string => Carbon::parse($this->date)->format('d M Y'))
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->modalHeading('Day to reconcile')
                ->modalWidth(Width::Small)
                ->modalSubmitActionLabel('Show')
                ->fillForm(fn (): array => ['date' => $this->date])
                ->schema([
                    DatePicker::make('date')
                        ->native(false)
                        ->maxDate(now())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->date = $this->validDate($data['date'] ?? null);
                    $this->reconciliation = null;
                    $this->flushCachedTableRecords();
                }),
            Action::make('openWagerOps')
                ->label('Wager ops')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('gray')
                ->url(SportsbookWagerOpsPage::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => collect($this->reconciliation()['rows'])
                ->mapWithKeys(fn (array $row): array => [$row['key'] => [...$row, ArrayRecord::getKeyName() => $row['key']]]))
            ->columns([
                TextColumn::make('product')
                    ->weight('bold'),
                TextColumn::make('measure'),
                TextColumn::make('site')
                    ->label('Kadi site')
                    ->alignEnd(),
                TextColumn::make('api')
                    ->label('Kadi API')
                    ->alignEnd(),
                TextColumn::make('difference')
                    ->label('Site − API')
                    ->alignEnd()
                    ->color('danger')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'match' => 'success',
                        'differs' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing to compare')
            ->emptyStateDescription('One side could not be read; see above.');
    }

    protected function validDate(?string $date): string
    {
        $today = now(config('app.timezone'))->toDateString();

        if ($date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $today;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date, config('app.timezone'))->toDateString();
        } catch (\Throwable) {
            return $today;
        }

        return $parsed > $today ? $today : $parsed;
    }
}
