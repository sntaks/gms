<?php

namespace App\Filament\Pages;

use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

/**
 * Game and competition wallets still holding players' money
 * (`GET /finance/open-wallets`), oldest activity first: a game left open, or a
 * tournament or jackpot not settled, keeps stakes in escrow.
 */
class OpenWalletsReport extends FinanceListReportPage
{
    public const KINDS = ['game' => 'Single game', 'tournament' => 'Tournament', 'jackpot' => 'Jackpot'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-open';

    protected static ?string $navigationLabel = 'Open Wallets';

    /**
     * Beside Competitions (same sort; pages are discovered alphabetically).
     */
    protected static ?int $navigationSort = 22;

    protected ?string $heading = 'Open Game Wallets';

    protected function reportKey(): string
    {
        return 'open-wallets';
    }

    protected function exportKey(): ?string
    {
        return 'open-wallets';
    }

    protected function usesPeriod(): bool
    {
        return false;
    }

    protected function extraFilters(): array
    {
        return ['kind' => $this->filterValue('kind')];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $stat = fn (string $label, array $section, string $icon, string $color): array => [
            'label' => $label,
            'value' => Format::money($section['held'] ?? 0),
            'description' => number_format((int) ($section['wallets'] ?? 0)).' wallets'.(filled($section['oldest_activity_at'] ?? null) ? ', oldest active '.Format::dateTime($section['oldest_activity_at']) : ''),
            'icon' => $icon,
            'color' => $color,
        ];

        return [
            $stat('Held in Open Wallets', $summary['total'] ?? [], 'heroicon-m-lock-open', ((float) ($summary['total']['held'] ?? 0)) > 0 ? 'warning' : 'success'),
            $stat('Single Games', $summary['games'] ?? [], 'heroicon-m-puzzle-piece', 'info'),
            $stat('Tournaments', $summary['tournaments'] ?? [], 'heroicon-m-trophy', 'info'),
            $stat('Jackpots', $summary['jackpots'] ?? [], 'heroicon-m-star', 'info'),
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('last_activity_at')->label('Last activity')->formatStateUsing(fn ($state): string => Format::dateTime($state))
                ->description(fn (array $record): ?string => isset($record['idle_minutes']) ? 'Idle '.number_format((int) $record['idle_minutes']).' min' : null),
            TextColumn::make('kind')->badge()->formatStateUsing(fn (?string $state): string => self::KINDS[$state] ?? ucfirst((string) $state))
                ->description(fn (array $record): ?string => ($record['under_complaint'] ?? false) ? 'Under complaint' : null),
            TextColumn::make('reference')->label('Game / competition')
                ->state(fn (array $record): ?string => $record['game_id'] ?? $record['cmp_uid'] ?? null)
                ->copyable()
                ->placeholder('—'),
            TextColumn::make('players')->alignCenter()->placeholder('—'),
            TextColumn::make('rounds')->alignCenter()->placeholder('—'),
            TextColumn::make('balance')->label('Held')->alignEnd()->weight('bold')->formatStateUsing(fn ($state): string => Format::money($state)),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [SelectFilter::make('kind')->options(self::KINDS)];
    }

    protected function emptyHeading(): string
    {
        return 'No open wallets';
    }
}
