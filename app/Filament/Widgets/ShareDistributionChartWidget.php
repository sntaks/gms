<?php

namespace App\Filament\Widgets;

use App\Models\Holder;
use Filament\Widgets\ChartWidget;

class ShareDistributionChartWidget extends ChartWidget
{
    protected ?string $heading = 'Share Ownership Distribution';

    protected static ?int $sort = 33;

    protected ?string $pollingInterval = '300s';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    protected function getData(): array
    {
        $holders = Holder::query()
            ->orderByDesc('share')
            ->get(['name', 'share']);

        $labels = $holders->pluck('name')->all();
        $data = $holders->pluck('share')
            ->map(fn ($share): float => round((float) $share * 100, 2))
            ->all();

        $palette = ['#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#ec4899', '#ef4444', '#14b8a6', '#f97316', '#6366f1', '#84cc16'];
        $colors = array_map(fn (int $index): string => $palette[$index % count($palette)], array_keys($data));

        $allocated = array_sum($data);
        if ($allocated < 100) {
            $labels[] = 'Unallocated';
            $data[] = round(100 - $allocated, 2);
            $colors[] = '#9ca3af';
        }

        return [
            'datasets' => [[
                'label' => 'Share %',
                'data' => $data,
                'backgroundColor' => $colors,
                'borderWidth' => 0,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
