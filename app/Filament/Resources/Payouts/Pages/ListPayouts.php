<?php

namespace App\Filament\Resources\Payouts\Pages;

use App\Filament\Resources\Payouts\PayoutResource;
use App\Filament\Resources\Payouts\Widgets\PayoutStatsWidget;
use App\Models\Payout;
use App\Services\PayoutDailyCap;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use ZeeshanTariq\FilamentStickyColumns\Concerns\InteractsWithStickyableColumns;

class ListPayouts extends ListRecords
{
    use InteractsWithStickyableColumns;

    protected static string $resource = PayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(fn (array $data, PayoutDailyCap $dailyCap): Payout => $dailyCap->withLock(fn (): Payout => Payout::create($data)))
                ->after(fn () => $this->dispatch('refresh-sidebar')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PayoutStatsWidget::class,
        ];
    }
}
