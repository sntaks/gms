<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Pages\ApiExpensesReport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Widgets\ExpenseStatsWidget;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // New expenses go to the Kadi API's ledger; this list is history.
            Action::make('recordInApi')
                ->label('Record an expense')
                ->icon('heroicon-o-plus-circle')
                ->visible(fn (): bool => ApiExpensesReport::canRecord())
                ->url(fn (): string => ApiExpensesReport::getUrl()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ExpenseStatsWidget::class,
        ];
    }
}
