<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Monthly excise duty returns: what was due to KRA for each month, what was
 * remitted and what is still outstanding. Returns and payment are due by the
 * 20th of the following month.
 */
class ExciseReturnsReport extends FinanceListReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Excise Returns';

    protected static ?int $navigationSort = 16;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::TAX;

    protected ?string $heading = 'Excise Duty Returns';

    /**
     * Returns are monthly, so default to the year rather than a single month.
     */
    #[Url]
    public string $period = 'this_year';

    protected function reportKey(): string
    {
        return 'excise-duty/returns';
    }

    protected function exportKey(): ?string
    {
        return 'excise-duty-returns';
    }

    protected function summaryStats(array $data): array
    {
        $items = collect($data['items'] ?? [])->filter(fn ($item): bool => is_array($item));
        $overdue = $items->filter(fn (array $item): bool => (bool) ($item['overdue'] ?? false));

        return [
            ['label' => 'Payable to KRA', 'value' => Format::money($data['payable'] ?? 0), 'description' => 'Outstanding across all months', 'icon' => 'heroicon-m-building-library', 'color' => ($data['payable'] ?? 0) > 0 ? 'warning' : 'success'],
            ['label' => 'Overdue Returns', 'value' => number_format($overdue->count()), 'description' => Format::money($overdue->sum(fn (array $item): float => (float) ($item['outstanding'] ?? 0))).' past the '.$this->ordinal((int) ($data['filing_day'] ?? 20)), 'icon' => 'heroicon-m-exclamation-triangle', 'color' => $overdue->isNotEmpty() ? 'danger' : 'success'],
            ['label' => 'Excise Due', 'value' => Format::money($items->sum(fn (array $item): float => (float) ($item['excise_due'] ?? 0))), 'description' => 'Remitted '.Format::money($items->sum(fn (array $item): float => (float) ($item['excise_remitted'] ?? 0))), 'icon' => 'heroicon-m-receipt-percent', 'color' => 'info'],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('period_start')
                ->label('Month')
                ->weight('bold')
                ->formatStateUsing(fn ($state, array $record): string => filled($state) ? Format::date($state).' – '.Format::date($record['period_end'] ?? null) : (string) ($record['period'] ?? '—')),
            TextColumn::make('due_date')
                ->label('Due')
                ->formatStateUsing(fn ($state): string => Format::date($state))
                ->color(fn (array $record): ?string => ($record['overdue'] ?? false) ? 'danger' : null)
                ->icon(fn (array $record): ?string => ($record['overdue'] ?? false) ? 'heroicon-m-exclamation-triangle' : null)
                ->description(fn (array $record): ?string => ($record['overdue'] ?? false) ? 'Overdue' : null),
            TextColumn::make('charges')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => number_format((int) $state)),
            TextColumn::make('gross_deposits')
                ->label('Gross Deposits')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('excise_due')
                ->label('Excise Due')
                ->alignEnd()
                ->weight('bold')
                ->description(fn (array $record): ?string => (float) ($record['excise_reversed'] ?? 0) > 0 ? 'Reversed '.Format::money($record['excise_reversed']) : null)
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('excise_remitted')
                ->label('Remitted')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('outstanding')
                ->alignEnd()
                ->weight('bold')
                ->color(fn ($state): string => (float) $state > 0 ? 'warning' : 'success')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
        ];
    }

    protected function ordinal(int $day): string
    {
        return $day.match (true) {
            in_array($day % 100, [11, 12, 13], true) => 'th',
            $day % 10 === 1 => 'st',
            $day % 10 === 2 => 'nd',
            $day % 10 === 3 => 'rd',
            default => 'th',
        };
    }

    protected function emptyHeading(): string
    {
        return 'No excise duty returns in this period';
    }
}
