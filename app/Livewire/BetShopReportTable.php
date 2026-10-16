<?php

namespace App\Livewire;

use App\Filament\Pages\BetShopsReport;
use App\Filament\Pages\FinanceReportPage;
use App\Services\GameApiService;
use App\Support\Format;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

/**
 * One bet shop's finance figures for a period on its Report tab, from
 * `/finance/bet-shops?bet_shop_id=`, whose `summary` is that shop alone. Shown one
 * figure per row so the float, counter cash and GGR read top to bottom. Finance only.
 */
class BetShopReportTable extends TableWidget
{
    public const PERIODS = [
        'today' => 'Today',
        'week' => 'This week',
        'month' => 'This month',
        'last_30_days' => 'Last 30 days',
        'year' => 'Last 12 months',
    ];

    /**
     * The figures shown, in order: key => [label, money?, note]. Count figures are not money.
     *
     * @var array<string, array{0: string, 1: bool, 2: ?string}>
     */
    public const FIGURES = [
        'float_balance' => ['Float now', true, 'As of now, whatever the period'],
        'float_purchased' => ['Float bought', true, null],
        'float_redeemed' => ['Float paid back to the owner', true, null],
        'loads' => ['Cash loads', false, 'Completed'],
        'loads_gross' => ['Cash loaded', true, 'Gross, as paid by players'],
        'loads_excise' => ['Excise duty on loads', true, null],
        'loads_net' => ['Credited to player wallets', true, null],
        'loads_reversed' => ['Loads reversed', false, null],
        'cash_outs' => ['Cash withdrawals paid', false, null],
        'cash_out_amount' => ['Cash paid out', true, null],
        'withdrawals_pending' => ['Withdrawals waiting', false, 'As of now, whatever the period'],
        'withdrawals_pending_amount' => ['Waiting to be collected', true, 'As of now, whatever the period'],
        'game_stakes' => ['Game stakes (settled games)', true, null],
        'game_stakes_open' => ['Game stakes in open games', true, 'Left out of GGR until settled'],
        'game_winnings' => ['Game winnings', true, null],
        'game_ggr' => ['Game GGR', true, null],
        'competition_entries' => ['Competition entries', true, null],
        'competition_prizes' => ['Competition prizes', true, null],
        'competition_held' => ['Still held in competitions', true, null],
        'competition_ggr' => ['Competition GGR', true, null],
        'ggr' => ['GGR', true, null],
    ];

    #[Locked]
    public int $shopId;

    public bool $apiError = false;

    protected int|string|array $columnSpan = 'full';

    public function mount(int $shopId): void
    {
        abort_unless(FinanceReportPage::canAccess(), 403);

        $this->shopId = $shopId;
    }

    /**
     * The first and last day of a period, Nairobi days.
     *
     * @return array{0: string, 1: string}
     */
    public static function periodRange(string $period): array
    {
        $today = today();

        $from = match ($period) {
            'today' => $today->copy(),
            'week' => $today->copy()->startOfWeek(),
            'last_30_days' => $today->copy()->subDays(29),
            'year' => $today->copy()->subDays(365),
            default => $today->copy()->startOfMonth(),
        };

        return [$from->toDateString(), $today->toDateString()];
    }

    public static function isGgr(string $key): bool
    {
        return str_ends_with($key, 'ggr');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function figures(string $period): array
    {
        [$from, $to] = static::periodRange($period);

        try {
            $summary = app(GameApiService::class)->financeReport('bet-shops', ['bet_shop_id' => $this->shopId, 'from' => $from, 'to' => $to], 60)['summary'] ?? [];
            $this->apiError = false;
        } catch (\Throwable $e) {
            Log::warning('Bet shop report failed', ['shop' => $this->shopId, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        return collect(self::FIGURES)
            ->map(fn (array $figure, string $key): array => [
                ArrayRecord::getKeyName() => $key,
                'key' => $key,
                'label' => $figure[0],
                'note' => $figure[2],
                'value' => $summary[$key] ?? 0,
                'is_money' => $figure[1],
            ])
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Shop report')
            ->description(fn (): string => 'GGR: '.BetShopsReport::GGR_TOOLTIP)
            ->records(fn (array $filters): array => $this->figures($filters['period']['value'] ?? 'month'))
            ->columns([
                TextColumn::make('label')
                    ->label('Figure')
                    ->weight(fn (array $record): ?string => $record['key'] === 'ggr' ? 'bold' : null)
                    ->description(fn (array $record): ?string => $record['note']),
                TextColumn::make('value')
                    ->alignEnd()
                    ->weight(fn (array $record): ?string => $record['key'] === 'ggr' ? 'bold' : null)
                    ->color(fn (array $record): ?string => static::isGgr($record['key']) ? BetShopsReport::signedMoneyColor($record['value']) : null)
                    ->tooltip(fn (array $record): ?string => static::isGgr($record['key']) ? BetShopsReport::GGR_TOOLTIP : null)
                    ->formatStateUsing(fn ($state, array $record): string => $record['is_money'] ? Format::money($state) : number_format((int) $state)),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->options(self::PERIODS)
                    ->default('month')
                    ->selectablePlaceholder(false),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Could not be loaded from KadiApi' : 'No figures')
            ->emptyStateDescription(fn (): ?string => $this->apiError ? 'Refresh the page to try again.' : null);
    }

    public static function canView(): bool
    {
        return FinanceReportPage::canAccess();
    }

    public function placeholder(): View
    {
        return view('livewire.customer-referral-placeholder');
    }
}
