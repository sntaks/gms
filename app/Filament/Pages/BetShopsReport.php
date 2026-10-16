<?php

namespace App\Filament\Pages;

use App\Livewire\ShopLoadsTable;
use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

/**
 * One row per bet shop from `/finance/bet-shops`: float bought and paid back, cash
 * loaded and paid out at the counter, and the gross gaming revenue of play at the
 * shop. Float and pending withdrawals are as of now; everything else is for the
 * period. GGR can be negative when players at a shop won more than they staked.
 */
class BetShopsReport extends FinanceListReportPage
{
    public const GGR_TOOLTIP = 'Stakes made at the shop minus what those players won from the same games. Open games are left out until settled.';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Bet Shops';

    protected static ?int $navigationSort = 30;

    protected ?string $heading = 'Bet Shops';

    protected ?string $subheading = 'Float, counter cash and gross gaming revenue per shop. Float and pending withdrawals are as of now.';

    protected function reportKey(): string
    {
        return 'bet-shops';
    }

    protected function exportKey(): ?string
    {
        return 'bet-shops';
    }

    protected function extraFilters(): array
    {
        return ['status' => $this->filterValue('status')];
    }

    /**
     * Money shown in red when it is negative, e.g. a shop's GGR.
     */
    public static function signedMoneyColor(mixed $amount): ?string
    {
        return (float) $amount < 0 ? 'danger' : null;
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $ggr = (float) ($summary['ggr'] ?? 0);

        return [
            ['label' => 'Float Held', 'value' => Format::money($summary['float_balance'] ?? 0), 'description' => number_format((int) ($summary['shops'] ?? 0)).' shops · owed back to owners if redeemed', 'icon' => 'heroicon-m-building-storefront', 'color' => 'primary'],
            ['label' => 'Float Bought', 'value' => Format::money($summary['float_purchased'] ?? 0), 'description' => 'Paid back '.Format::money($summary['float_redeemed'] ?? 0), 'icon' => 'heroicon-m-arrow-down-tray', 'color' => 'info'],
            ['label' => 'Cash Loaded', 'value' => Format::money($summary['loads_gross'] ?? 0), 'description' => number_format((int) ($summary['loads'] ?? 0)).' loads · excise '.Format::money($summary['loads_excise'] ?? 0).' · '.number_format((int) ($summary['loads_reversed'] ?? 0)).' reversed', 'icon' => 'heroicon-m-banknotes', 'color' => 'success'],
            ['label' => 'Cash Paid Out', 'value' => Format::money($summary['cash_out_amount'] ?? 0), 'description' => number_format((int) ($summary['cash_outs'] ?? 0)).' withdrawals · '.number_format((int) ($summary['withdrawals_pending'] ?? 0)).' pending ('.Format::money($summary['withdrawals_pending_amount'] ?? 0).')', 'icon' => 'heroicon-m-arrow-up-tray', 'color' => 'warning'],
            ['label' => 'Shop GGR', 'value' => Format::money($ggr), 'description' => 'Games '.Format::money($summary['game_ggr'] ?? 0).' · competitions '.Format::money($summary['competition_ggr'] ?? 0), 'icon' => 'heroicon-m-scale', 'color' => $ggr < 0 ? 'danger' : 'success'],
        ];
    }

    protected function columns(): array
    {
        $money = fn (string $name, string $label, bool $hidden = false): TextColumn => TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->formatStateUsing(fn ($state): string => Format::money($state))
            ->toggleable(isToggledHiddenByDefault: $hidden);

        $ggr = fn (string $name, string $label, bool $hidden = false): TextColumn => $money($name, $label, $hidden)
            ->color(fn ($state): ?string => static::signedMoneyColor($state))
            ->tooltip(self::GGR_TOOLTIP);

        return [
            TextColumn::make('shop_no')
                ->label('Shop')
                ->fontFamily('mono')
                ->weight('bold')
                ->description(fn (array $record): ?string => $record['name'] ?? null)
                ->url(fn (array $record): ?string => ShopLoadsTable::shopUrl($record['bet_shop_id'] ?? null)),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => BetShopsPage::statusColor($state))
                ->formatStateUsing(fn (?string $state): string => BetShopsPage::STATUSES[$state] ?? ucfirst((string) $state)),
            $money('float_balance', 'Float now')->weight('bold'),
            $money('float_purchased', 'Float bought'),
            $money('float_redeemed', 'Float paid back'),
            $money('loads_gross', 'Cash loaded')
                ->description(fn (array $record): string => number_format((int) ($record['loads'] ?? 0)).' loads · '.number_format((int) ($record['loads_reversed'] ?? 0)).' reversed'),
            $money('loads_excise', 'Load excise', hidden: true),
            $money('loads_net', 'To wallets', hidden: true),
            $money('cash_out_amount', 'Cash paid out')
                ->description(fn (array $record): string => number_format((int) ($record['cash_outs'] ?? 0)).' withdrawals'),
            $money('withdrawals_pending_amount', 'Pending withdrawals')
                ->description(fn (array $record): string => number_format((int) ($record['withdrawals_pending'] ?? 0)).' waiting'),
            $money('game_stakes', 'Game stakes', hidden: true)
                ->description(fn (array $record): string => Format::money($record['game_stakes_open'] ?? 0).' in open games'),
            $money('game_winnings', 'Game winnings', hidden: true),
            $ggr('game_ggr', 'Game GGR', hidden: true),
            $money('competition_entries', 'Competition entries', hidden: true),
            $money('competition_prizes', 'Competition prizes', hidden: true)
                ->description(fn (array $record): string => Format::money($record['competition_held'] ?? 0).' still held'),
            $ggr('competition_ggr', 'Competition GGR', hidden: true),
            $ggr('ggr', 'GGR')->weight('bold'),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            SelectFilter::make('status')->options(BetShopsPage::STATUSES),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No bet shops';
    }
}
