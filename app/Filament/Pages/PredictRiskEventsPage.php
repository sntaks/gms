<?php

namespace App\Filament\Pages;

use App\Services\GameApiService;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Risk flags the Kadi API raised on prediction bets (`GET /predict/admin/risk-events`).
 * A bet is never refused because of a flag; staff review it and close it with a
 * reason (`predict.settle`).
 */
class PredictRiskEventsPage extends PredictListPage
{
    /**
     * api RiskService::TYPES.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'predict_late_large_bet' => 'Large bet near the close',
        'predict_fresh_account' => 'New account staking big',
        'predict_side_cluster' => 'Many players piling onto one side',
        'predict_sharp_winner' => 'Player who wins too often',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Risk flags';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'predictions/risk';

    protected ?string $heading = 'Risk flags';

    protected ?string $subheading = 'Raised on prediction bets for review. A flag never refuses a bet.';

    public static function tabs(): array
    {
        return ['open' => 'To review', 'all' => 'All'];
    }

    protected function tabFilters(string $tab): array
    {
        return $tab === 'all' ? ['resolved' => 1] : [];
    }

    protected function fetch(array $filters): array
    {
        return app(GameApiService::class)->listPredictRiskEvents($filters);
    }

    protected function configureTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Raised')->formatStateUsing(fn (?string $state): string => Format::dateTime($state)),
                TextColumn::make('type')->weight('bold')->formatStateUsing(fn (?string $state): string => self::TYPES[$state] ?? (string) $state)
                    ->description(fn (array $record): string => 'Severity '.($record['severity'] ?? '—').', score '.($record['score'] ?? '—')),
                TextColumn::make('customer_id')->label('Customer')->formatStateUsing(fn ($state): string => '#'.$state),
                TextColumn::make('market_id')->label('Market')->formatStateUsing(fn ($state): string => '#'.$state)
                    ->url(fn (array $record): ?string => static::marketUrl($record['market_id'] ?? null))->placeholder('—'),
                TextColumn::make('details')
                    ->state(fn (array $record): string => collect($record['details'] ?? [])->except(['market_id'])->map(fn ($value, $key): string => str_replace('_', ' ', (string) $key).': '.(is_scalar($value) ? $value : json_encode($value)))->implode(' · '))
                    ->wrap(),
                TextColumn::make('resolved_at')->label('Reviewed')->formatStateUsing(fn (?string $state): string => Format::dateTime($state))->placeholder('Open'),
            ])
            ->recordActions([
                Action::make('resolveRiskEvent')
                    ->label('Close')
                    ->icon('heroicon-o-check')
                    ->visible(fn (array $record): bool => static::canSettlePredict() && ($record['resolved_at'] ?? null) === null)
                    ->modalHeading('Close this flag as reviewed')
                    ->modalSubmitActionLabel('Close flag')
                    ->schema([Textarea::make('reason')->label('What you found')->required()->maxLength(255)->rows(2)])
                    ->action(function (array $record, array $data, Action $action): void {
                        abort_unless(static::canSettlePredict(), 403);

                        $id = (int) $record['id'];
                        $payload = ['admin_user' => static::adminUser(), 'reason' => trim($data['reason'])];
                        $result = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->resolvePredictRiskEvent($id, $payload), 'PredictRiskEvent', $id, 'resolve', $payload, 'Could not close the flag', ['reason' => 'reason']);

                        if ($result !== null) {
                            Notification::make()->title('Flag closed')->success()->send();
                            $this->afterPredictWrite();
                        }
                    }),
            ]);
    }
}
