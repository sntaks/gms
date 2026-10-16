<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use UnitEnum;

/**
 * Referral bonuses credited to referrers' referral wallets, from
 * `/finance/referrals/bonuses`. Earning a bonus moves no cash; it becomes an
 * expense only when it is withdrawn (see {@see ReferralPayoutsReport}).
 */
class ReferralBonusesReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Referral Bonuses';

    protected static ?int $navigationSort = 27;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::PROMOTIONS;

    protected ?string $heading = 'Referral Bonuses';

    protected function reportKey(): string
    {
        return 'referrals/bonuses';
    }

    protected function exportKey(): ?string
    {
        return 'referral-bonuses';
    }

    protected function extraFilters(): array
    {
        return [
            'type' => $this->filterValue('type'),
            'customer_id' => $this->tableFilters['customer']['customer_id'] ?? null,
        ];
    }

    protected function summaryStats(array $data): array
    {
        $byMilestone = $data['summary']['by_milestone'] ?? [];
        $signup = $byMilestone['signup'] ?? [];
        $firstDeposit = $byMilestone['first_deposit'] ?? [];

        return [
            ['label' => 'Bonuses Earned', 'value' => Format::money($data['summary']['total'] ?? 0), 'description' => 'Credited to referral wallets; no cash moves until withdrawn', 'icon' => 'heroicon-m-gift', 'color' => 'info'],
            ['label' => 'Signup Bonuses', 'value' => Format::money($signup['amount'] ?? 0), 'description' => number_format((int) ($signup['bonuses'] ?? 0)).' referrals verified', 'icon' => 'heroicon-m-check-badge', 'color' => 'success'],
            ['label' => 'First-Deposit Bonuses', 'value' => Format::money($firstDeposit['amount'] ?? 0), 'description' => number_format((int) ($firstDeposit['bonuses'] ?? 0)).' referrals deposited', 'icon' => 'heroicon-m-banknotes', 'color' => 'primary'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'By milestone',
                'headers' => ['Milestone', 'Bonuses', 'Amount'],
                'rows' => collect($data['summary']['by_milestone'] ?? [])
                    ->filter(fn ($row): bool => is_array($row))
                    ->map(fn (array $row, string $milestone): array => [
                        ReferralsPage::MILESTONES[$milestone] ?? ucfirst(str_replace('_', ' ', $milestone)),
                        number_format((int) ($row['bonuses'] ?? 0)),
                        Format::money($row['amount'] ?? 0),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('paid_at')
                ->label('Credited')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('referrer_name')
                ->label('Referrer')
                ->description(fn (array $record): string => '#'.($record['customer_id'] ?? '—'))
                ->color('primary')
                ->url(fn (array $record): ?string => ReferralWithdrawalsPage::customerUrl($record['customer_id'] ?? null)),
            TextColumn::make('referred_name')
                ->label('Referred player')
                ->description(fn (array $record): string => '#'.($record['referred_id'] ?? '—'))
                ->color('primary')
                ->url(fn (array $record): ?string => ReferralWithdrawalsPage::customerUrl($record['referred_id'] ?? null)),
            TextColumn::make('milestone')
                ->badge()
                ->color('success')
                ->formatStateUsing(fn (?string $state): string => ReferralsPage::MILESTONES[$state] ?? ucfirst(str_replace('_', ' ', (string) $state))),
            TextColumn::make('amount')
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('ledger_entry_id')
                ->label('Ledger entry')
                ->fontFamily('mono')
                ->copyable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('type')
                ->label('Milestone')
                ->options(ReferralsPage::MILESTONES),
            Filter::make('customer')
                ->schema([TextInput::make('customer_id')->label('Referrer customer ID')->integer()->minValue(1)])
                ->indicateUsing(fn (array $data): ?string => filled($data['customer_id'] ?? null) ? 'Referrer #'.$data['customer_id'] : null),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No referral bonuses in this period';
    }
}
