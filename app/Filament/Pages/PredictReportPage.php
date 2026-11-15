<?php

namespace App\Filament\Pages;

use App\Services\GameApiService;
use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Per-market stakes and results (`GET /predict/admin/reports/markets`): settled
 * markets show payouts, house net, the cut and the seed's result; markets on
 * quotes show what each side would pay and the worst case.
 */
class PredictReportPage extends PredictListPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Report';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'predictions/report';

    protected ?string $heading = 'Prediction markets report';

    protected ?string $subheading = 'Stakes and results per market, in KES.';

    protected static function recordKey(): string
    {
        return 'market_id';
    }

    protected function fetch(array $filters): array
    {
        return app(GameApiService::class)->predictReport([
            ...$filters,
            'status' => $this->tableFilters['status']['value'] ?? null,
            'category' => $this->tableFilters['category']['value'] ?? null,
        ]);
    }

    protected function configureTable(Table $table): Table
    {
        $money = fn (?string $label, string $key): TextColumn => TextColumn::make($key)
            ->label($label)
            ->alignEnd()
            ->formatStateUsing(fn ($state): string => Format::money($state))
            ->placeholder('—');

        return $table
            ->columns([
                TextColumn::make('question')->weight('bold')->wrap()
                    ->url(fn (array $record): ?string => static::marketUrl($record['market_id'] ?? null))
                    ->description(fn (array $record): string => (config('predict.statuses.'.($record['status'] ?? '')) ?? ($record['status'] ?? ''))
                        .(isset($record['result']) ? ' · '.ucfirst((string) $record['result']) : '')),
                TextColumn::make('bets')->alignEnd()->numeric(),
                $money('Stakes', 'stakes'),
                $money('Payouts', 'payouts'),
                $money('House net', 'house_net'),
                $money('Cut', 'cut'),
                $money('Seed result', 'seed_result'),
                $money('Pays if Yes', 'payout_if_yes'),
                $money('Pays if No', 'payout_if_no'),
                $money('Worst case', 'worst_case_house_net'),
            ])
            ->filters([
                SelectFilter::make('status')->options(config('predict.statuses')),
                SelectFilter::make('category')->options(config('predict.categories')),
            ]);
    }
}
