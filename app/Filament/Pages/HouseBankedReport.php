<?php

namespace App\Filament\Pages;

use App\Support\Format;
use BackedEnum;

/**
 * House-banked games per product (`GET /finance/house-banked`): slots, scratch
 * cards, crash, the sportsbook and prediction pools, where the house takes the
 * other side. Settlement-based and net of reversals; ggr equals the income
 * statement's house_banked line.
 */
class HouseBankedReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'House-banked games';

    /**
     * Beside the Games report (same sort; pages are discovered alphabetically).
     */
    protected static ?int $navigationSort = 21;

    protected ?string $heading = 'House-banked games';

    protected function reportKey(): string
    {
        return 'house-banked';
    }

    protected function exportKey(): ?string
    {
        return 'house-banked';
    }

    protected function summaryStats(array $data): array
    {
        $totals = $data['totals'] ?? [];
        $percent = fn ($value): ?string => $value === null ? null : number_format((float) $value, 1).'%';

        return [
            ['label' => 'Turnover', 'value' => Format::money($totals['turnover'] ?? 0), 'description' => number_format((int) ($totals['bets'] ?? 0)).' bets, '.number_format((int) ($totals['players'] ?? 0)).' players', 'icon' => 'heroicon-m-banknotes', 'color' => 'info'],
            ['label' => 'Payouts', 'value' => Format::money($totals['payouts'] ?? 0), 'description' => ($percent($totals['rtp'] ?? null) ?? '—').' RTP', 'icon' => 'heroicon-m-arrow-up-right', 'color' => 'warning'],
            ['label' => 'GGR', 'value' => Format::money($totals['ggr'] ?? 0), 'description' => ($percent($totals['hold'] ?? null) ?? '—').' hold', 'icon' => 'heroicon-m-home', 'color' => 'success'],
            ['label' => 'Net after promotions', 'value' => Format::money($totals['net'] ?? 0), 'description' => 'Winnings tax withheld '.Format::money($totals['winnings_tax_withheld'] ?? 0), 'icon' => 'heroicon-m-scale', 'color' => 'primary'],
        ];
    }

    protected function blocks(array $data): array
    {
        $percent = fn ($value): string => $value === null ? '—' : number_format((float) $value, 1).'%';

        return [[
            'title' => 'By product',
            'headers' => ['Product', 'Bets', 'Voids', 'Turnover', 'Payouts', 'RTP', 'GGR', 'Hold', 'Bonuses', 'Free-bet winnings', 'Net'],
            'rows' => collect($data['by_product'] ?? [])->map(fn (array $row): array => [
                ($row['product'] ?? '?').(isset($row['game']) ? ' ('.$row['game'].')' : ''),
                number_format((int) ($row['bets'] ?? 0)),
                number_format((int) ($row['voids'] ?? 0)),
                Format::money($row['turnover'] ?? 0),
                Format::money($row['payouts'] ?? 0),
                $percent($row['rtp'] ?? null),
                Format::money($row['ggr'] ?? 0),
                $percent($row['hold'] ?? null),
                Format::money($row['bonuses'] ?? 0),
                Format::money($row['free_bet_winnings'] ?? 0),
                Format::money($row['net'] ?? 0),
            ])->all(),
        ]];
    }

    public function getWarnings(): array
    {
        return array_values(array_filter([...parent::getWarnings(), ...array_filter((array) ($this->getReport()['notes'] ?? []), 'is_string')]));
    }
}
