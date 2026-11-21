<?php

namespace App\Filament\Widgets\Stats;

/**
 * Players by how much they staked in the period.
 */
class StatsStakeBandsChartWidget extends StatsPlayersChartWidget
{
    protected ?string $heading = 'Players by total stakes';

    /**
     * @var array<string, string>
     */
    public const BANDS = [
        'under_100' => 'Under KES 100',
        '100_1k' => 'KES 100 – 1K',
        '1k_10k' => 'KES 1K – 10K',
        '10k_100k' => 'KES 10K – 100K',
        '100k_plus' => 'KES 100K and more',
    ];

    protected function explanation(): ?string
    {
        return 'Each active player counted once, by the total they staked in the period.';
    }

    protected function getData(): array
    {
        $bands = (array) ($this->stats()['stake_bands'] ?? []);

        return [
            'datasets' => [[
                'label' => 'Players',
                'data' => array_map(fn (string $band): int => (int) ($bands[$band]['players'] ?? 0), array_keys(self::BANDS)),
                'backgroundColor' => 'rgba(59,130,246,0.7)',
            ]],
            'labels' => array_values(self::BANDS),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
