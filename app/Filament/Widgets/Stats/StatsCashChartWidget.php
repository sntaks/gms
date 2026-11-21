<?php

namespace App\Filament\Widgets\Stats;

/**
 * Deposits and withdrawals (KES, left axis) with active players (right axis). Under a game or product
 * filter the API sends no cash figures, so only active players are drawn.
 */
class StatsCashChartWidget extends StatsSeriesChartWidget
{
    protected ?string $heading = 'Deposits, withdrawals and active players';

    protected function figures(): array
    {
        return [
            'deposits' => ['Deposits', '16,185,129', 'y'],
            'withdrawals' => ['Withdrawals', '239,68,68', 'y'],
            'active_players' => ['Active players', '139,92,246', 'players'],
        ];
    }
}
