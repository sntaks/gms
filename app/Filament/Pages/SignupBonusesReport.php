<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use UnitEnum;

/**
 * Signup bonuses granted in the period, from `/finance/promotions`. The house
 * wallet pays the gross amount; the excise duty on it is owed to KRA (and is in
 * the excise charges with source `promotion`) and the player keeps the net. The
 * gross is the `promotions` line on the income statement.
 */
class SignupBonusesReport extends FinanceListReportPage
{
    public const PROMOTIONS = [
        'signup_bonus' => 'Signup bonus',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Signup Bonuses';

    protected static ?int $navigationSort = 29;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::PROMOTIONS;

    protected ?string $heading = 'Signup Bonuses';

    protected function reportKey(): string
    {
        return 'promotions';
    }

    protected function exportKey(): ?string
    {
        return 'promotions';
    }

    public static function promotionLabel(?string $promotion): string
    {
        return self::PROMOTIONS[$promotion] ?? ucfirst(str_replace('_', ' ', (string) $promotion));
    }

    protected function extraFilters(): array
    {
        $code = $this->tableFilters['promo_code']['code'] ?? null;

        return [
            'kind' => filled($code) ? strtoupper(trim($code)) : null,
            'customer_id' => $this->tableFilters['customer']['customer_id'] ?? null,
        ];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];

        return [
            ['label' => 'Bonus Cost', 'value' => Format::money($summary['gross_amount'] ?? 0), 'description' => number_format((int) ($summary['credits'] ?? 0)).' bonuses paid from the house wallet', 'icon' => 'heroicon-m-ticket', 'color' => 'warning'],
            ['label' => 'Excise Owed', 'value' => Format::money($summary['excise_amount'] ?? 0), 'description' => 'Owed to KRA; in the excise charges as promotion', 'icon' => 'heroicon-m-receipt-percent', 'color' => 'info'],
            ['label' => 'Paid to Players', 'value' => Format::money($summary['net_amount'] ?? 0), 'description' => 'Credited to main wallets, locked until staked', 'icon' => 'heroicon-m-user-group', 'color' => 'primary'],
        ];
    }

    protected function progressBars(array $data): array
    {
        $summary = $data['summary'] ?? [];

        if (($summary['budget_cap'] ?? null) === null) {
            return [];
        }

        $cap = (float) $summary['budget_cap'];
        $spent = (float) ($summary['signup_bonus_spent'] ?? 0);

        return [[
            'label' => 'Signup bonus budget',
            'value' => $spent,
            'max' => $cap,
            'description' => Format::money($spent).' of '.Format::money($cap).' spent, gross, all time. KadiApi stops granting bonuses once the cap is reached.',
        ]];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'By promotion',
                'headers' => ['Promotion', 'Bonuses', 'Gross (house paid)', 'Excise', 'Net (players kept)'],
                'rows' => collect($data['summary']['by_promotion'] ?? [])
                    ->filter(fn ($row): bool => is_array($row))
                    ->map(fn (array $row, string $promotion): array => [
                        static::promotionLabel($promotion),
                        number_format((int) ($row['credits'] ?? 0)),
                        Format::money($row['gross_amount'] ?? 0),
                        Format::money($row['excise_amount'] ?? 0),
                        Format::money($row['net_amount'] ?? 0),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('granted_at')
                ->label('Granted')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
            TextColumn::make('customer_name')
                ->label('Customer')
                ->description(fn (array $record): string => '#'.($record['customer_id'] ?? '—'))
                ->color('primary')
                ->placeholder('—')
                ->url(fn (array $record): ?string => ReferralWithdrawalsPage::customerUrl($record['customer_id'] ?? null)),
            TextColumn::make('promo_code')
                ->label('Code')
                ->fontFamily('mono')
                ->copyable(),
            TextColumn::make('gross_amount')
                ->label('Gross')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('rate')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => ExciseDutyReport::percent((float) $state)),
            TextColumn::make('excise_amount')
                ->label('Excise')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('net_amount')
                ->label('Net')
                ->alignEnd()
                ->weight('bold')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextColumn::make('status')
                ->badge()
                ->color(fn (?string $state): string => $state === 'granted' ? 'success' : 'gray')
                ->formatStateUsing(fn (?string $state): string => ucfirst(str_replace('_', ' ', (string) $state))),
            TextColumn::make('ledger_entry_id')
                ->label('Ledger entry')
                ->fontFamily('mono')
                ->copyable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    protected function tableFilterDefinitions(): array
    {
        return [
            Filter::make('promo_code')
                ->schema([TextInput::make('code')->label('Promo code')->maxLength(30)])
                ->indicateUsing(fn (array $data): ?string => filled($data['code'] ?? null) ? 'Code '.strtoupper(trim($data['code'])) : null),
            Filter::make('customer')
                ->schema([TextInput::make('customer_id')->label('Customer ID')->integer()->minValue(1)])
                ->indicateUsing(fn (array $data): ?string => filled($data['customer_id'] ?? null) ? 'Customer #'.$data['customer_id'] : null),
        ];
    }

    protected function emptyHeading(): string
    {
        return 'No signup bonuses in this period';
    }
}
