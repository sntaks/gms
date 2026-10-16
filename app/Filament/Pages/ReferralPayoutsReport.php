<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Referral withdrawals to M-Pesa requested in the period, from
 * `/finance/referrals/withdrawals`. Completed ones are the referral programme
 * expense. Rows open the withdrawal, where a stuck one can be settled.
 */
class ReferralPayoutsReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Referral Payouts';

    protected static ?int $navigationSort = 28;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::PROMOTIONS;

    protected ?string $heading = 'Referral Payouts';

    protected function reportKey(): string
    {
        return 'referrals/withdrawals';
    }

    protected function exportKey(): ?string
    {
        return 'referral-withdrawals';
    }

    protected function extraFilters(): array
    {
        return [
            'status' => $this->filterValue('status'),
            'customer_id' => $this->tableFilters['customer']['customer_id'] ?? null,
        ];
    }

    protected function summaryStats(array $data): array
    {
        $byStatus = $data['summary']['by_status'] ?? [];
        $open = collect(['pending', 'processing'])->map(fn (string $status): array => $byStatus[$status] ?? []);

        return [
            ['label' => 'Paid (Expense)', 'value' => Format::money($byStatus['completed']['amount'] ?? 0), 'description' => number_format((int) ($byStatus['completed']['withdrawals'] ?? 0)).' completed from 4151665', 'icon' => 'heroicon-m-banknotes', 'color' => 'primary'],
            ['label' => 'Open', 'value' => Format::money($open->sum(fn (array $row): float => (float) ($row['amount'] ?? 0))), 'description' => number_format($open->sum(fn (array $row): int => (int) ($row['withdrawals'] ?? 0))).' pending or processing', 'icon' => 'heroicon-m-clock', 'color' => 'warning'],
            ['label' => 'Failed', 'value' => Format::money($byStatus['failed']['amount'] ?? 0), 'description' => number_format((int) ($byStatus['failed']['withdrawals'] ?? 0)).' refunded to referral wallets', 'icon' => 'heroicon-m-x-circle', 'color' => 'danger'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'By status',
                'headers' => ['Status', 'Withdrawals', 'Amount'],
                'rows' => collect($data['summary']['by_status'] ?? [])
                    ->filter(fn ($row): bool => is_array($row))
                    ->map(fn (array $row, string $status): array => [
                        ReferralWithdrawalsPage::STATUSES[$status] ?? ucfirst($status),
                        number_format((int) ($row['withdrawals'] ?? 0)),
                        Format::money($row['amount'] ?? 0),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (array $record): ?string => isset($record['id']) && ReferralWithdrawalDetailPage::canAccess()
                ? ReferralWithdrawalDetailPage::getUrl(['withdrawal' => $record['id']])
                : null);
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('requested_at')
                ->label('Requested')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('customer_name')
                ->label('Customer')
                ->description(fn (array $record): string => '#'.($record['customer_id'] ?? '—').' · '.($record['phone_no'] ?? '—')),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => ReferralWithdrawalsPage::statusColor($state))
                ->formatStateUsing(fn (?string $state): string => ReferralWithdrawalsPage::STATUSES[$state] ?? ucfirst((string) $state)),
            TextColumn::make('amount')
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('mpesa_receipt')
                ->label('M-Pesa receipt')
                ->placeholder('—')
                ->fontFamily('mono'),
            TextColumn::make('result_desc')
                ->label('Result')
                ->placeholder('—')
                ->limit(40)
                ->tooltip(fn (array $record): ?string => $record['result_desc'] ?? null),
            TextColumn::make('completed_at')
                ->label('Completed')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('failed_at')
                ->label('Failed')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state))
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('status')->options(ReferralWithdrawalsPage::STATUSES),
            Filter::make('customer')
                ->schema([TextInput::make('customer_id')->label('Customer ID')->integer()->minValue(1)])
                ->indicateUsing(fn (array $data): ?string => filled($data['customer_id'] ?? null) ? 'Customer #'.$data['customer_id'] : null),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No referral payouts in this period';
    }
}
