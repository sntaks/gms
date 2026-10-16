<?php

namespace App\Filament\Pages;

use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use UnitEnum;

class PlayerWithdrawalsPage extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Player Withdrawals';

    protected static ?string $cluster = null;

    protected static string|UnitEnum|null $navigationGroup = '📊 Finance';

    protected static ?int $navigationSort = 3;

    protected ?string $heading = 'Player Withdrawals';

    protected function reportKey(): string
    {
        return 'withdrawals';
    }

    protected function exportKey(): ?string
    {
        return 'withdrawals';
    }

    protected function extraFilters(): array
    {
        return ['status' => $this->filterValue('status')];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $stuck = $summary['stuck_pending'] ?? [];
        $oldest = $summary['oldest_pending_hours'] ?? null;

        return [
            ['label' => 'Payouts', 'value' => number_format((int) ($summary['payments'] ?? 0)), 'description' => 'In the selected period', 'icon' => 'heroicon-m-arrow-up-tray', 'color' => 'primary'],
            ['label' => 'Total Requested', 'value' => Format::money($summary['amount'] ?? 0), 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
            ['label' => 'Stuck Pending', 'value' => Format::money($stuck['amount'] ?? 0), 'description' => number_format((int) ($stuck['payments'] ?? 0)).' pending over '.($stuck['threshold_hours'] ?? 24).'h', 'icon' => 'heroicon-m-exclamation-triangle', 'color' => ($stuck['payments'] ?? 0) > 0 ? 'danger' : 'gray'],
            ['label' => 'Oldest Pending', 'value' => $oldest === null ? '—' : number_format((float) $oldest, 1).' h', 'description' => 'Time since the oldest unpaid request', 'icon' => 'heroicon-m-clock', 'color' => 'warning'],
        ];
    }

    protected function blocks(array $data): array
    {
        $summary = $data['summary'] ?? [];

        return [
            [
                'title' => 'By status',
                'headers' => ['Status', 'Payouts', 'Amount'],
                'rows' => collect($summary['by_status'] ?? [])
                    ->map(fn (array $row, string $status): array => [ucfirst($status), number_format((int) ($row['payments'] ?? 0)), Format::money($row['amount'] ?? 0)])
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Failure reasons',
                'headers' => ['Reason', 'Payouts', 'Amount'],
                'rows' => collect($summary['failure_reasons'] ?? [])
                    ->map(fn (array $row, $key): array => [
                        (string) ($row['reason'] ?? (is_string($key) ? $key : '—')),
                        number_format((int) ($row['payments'] ?? $row['count'] ?? 0)),
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
            TextColumn::make('created_at')
                ->label('Date')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('customer_name')
                ->label('Player')
                ->weight('bold')
                ->placeholder('—'),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => match ($state) {
                    'paid' => 'success',
                    'pending' => 'warning',
                    'failed' => 'danger',
                    default => 'gray',
                })
                ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
            TextColumn::make('failure_reason')
                ->label('Failure Reason')
                ->color('gray')
                ->placeholder('—'),
            TextColumn::make('amount')
                ->label('Amount')
                ->weight('bold')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed']),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No withdrawals found';
    }
}
