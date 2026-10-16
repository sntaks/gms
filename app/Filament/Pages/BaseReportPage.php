<?php

namespace App\Filament\Pages;

use App\Support\Format;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

/**
 * Shared base for the finance and game reports. Owns one filter dimension — the report
 * period — exposed through a single Header Filter Action and persisted in the
 * URL query string so reports stay shareable. Subclasses read the resolved
 * range via {@see dateRange()}.
 */
abstract class BaseReportPage extends Page
{
    #[Url]
    public string $period = 'this_month';

    #[Url]
    public ?string $customStart = null;

    #[Url]
    public ?string $customEnd = null;

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('reports.view') ?? false;
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'this_week' => 'This Week',
            'last_week' => 'Last Week',
            'this_month' => 'This Month',
            'last_month' => 'Last Month',
            '1st_qtr' => '1st Qtr',
            '2nd_qtr' => '2nd Qtr',
            '3rd_qtr' => '3rd Qtr',
            '4th_qtr' => '4th Qtr',
            'half_year' => 'Half a Year',
            'this_year' => 'This Year',
            'last_year' => 'Last Year',
            'all_time' => 'All Time',
            'custom' => 'Custom Range',
        ];
    }

    /**
     * Resolve the active period to a concrete date range. `all_time` returns
     * nulls so callers can decide their own open-ended bounds.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function dateRange(): array
    {
        return match ($this->period) {
            'today' => [today()->toDateString(), today()->toDateString()],
            'yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
            'this_week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
            'last_week' => [now()->subWeek()->startOfWeek()->toDateString(), now()->subWeek()->endOfWeek()->toDateString()],
            'this_month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            'last_month' => [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()],
            '1st_qtr' => [now()->startOfYear()->toDateString(), now()->startOfYear()->addMonths(2)->endOfMonth()->toDateString()],
            '2nd_qtr' => [now()->startOfYear()->addMonths(3)->startOfMonth()->toDateString(), now()->startOfYear()->addMonths(5)->endOfMonth()->toDateString()],
            '3rd_qtr' => [now()->startOfYear()->addMonths(6)->startOfMonth()->toDateString(), now()->startOfYear()->addMonths(8)->endOfMonth()->toDateString()],
            '4th_qtr' => [now()->startOfYear()->addMonths(9)->startOfMonth()->toDateString(), now()->endOfYear()->toDateString()],
            'half_year' => [now()->startOfYear()->toDateString(), now()->startOfYear()->addMonths(5)->endOfMonth()->toDateString()],
            'this_year' => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
            'last_year' => [now()->subYear()->startOfYear()->toDateString(), now()->subYear()->endOfYear()->toDateString()],
            'all_time' => [null, null],
            'custom' => [$this->customStart ?? now()->startOfMonth()->toDateString(), $this->customEnd ?? now()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
    }

    /**
     * Human label for the active period, shown on the filter action button.
     */
    public function periodLabel(): string
    {
        if ($this->period === 'custom') {
            [$start, $end] = $this->dateRange();

            return Format::date($start).' – '.Format::date($end);
        }

        return $this->periodOptions()[$this->period] ?? 'This Month';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('filterPeriod')
                ->label(fn (): string => $this->periodLabel())
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->modalHeading('Report period')
                ->modalDescription('Choose the date range this report covers.')
                ->modalWidth(Width::Medium)
                ->modalSubmitActionLabel('Apply')
                ->fillForm(fn (): array => [
                    'period' => $this->period,
                    'customStart' => $this->customStart,
                    'customEnd' => $this->customEnd,
                ])
                ->schema([
                    Select::make('period')
                        ->label('Period')
                        ->options($this->periodOptions())
                        ->live()
                        ->required(),
                    DatePicker::make('customStart')
                        ->label('From')
                        ->native(false)
                        ->maxDate(now())
                        ->visible(fn ($get): bool => $get('period') === 'custom')
                        ->required(fn ($get): bool => $get('period') === 'custom'),
                    DatePicker::make('customEnd')
                        ->label('Until')
                        ->native(false)
                        ->maxDate(now())
                        ->afterOrEqual('customStart')
                        ->visible(fn ($get): bool => $get('period') === 'custom')
                        ->required(fn ($get): bool => $get('period') === 'custom'),
                ])
                ->action(function (array $data): void {
                    $this->period = $data['period'];
                    $this->customStart = $data['period'] === 'custom' ? ($data['customStart'] ?? null) : null;
                    $this->customEnd = $data['period'] === 'custom' ? ($data['customEnd'] ?? null) : null;
                    $this->onFilterApplied();
                }),
        ];
    }

    /**
     * Hook fired after the period filter changes. Table-backed reports override
     * this to reset pagination.
     */
    protected function onFilterApplied(): void {}
}
