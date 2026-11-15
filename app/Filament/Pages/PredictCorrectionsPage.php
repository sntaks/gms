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
 * Corrections of settled markets' results (`GET /predict/admin/corrections`).
 * One admin requests (from the market page), a different admin approves; the
 * Kadi API also refuses an approval by the requester (403 `same_admin`).
 * Approving, cancelling and retrying need `predict.settle`.
 */
class PredictCorrectionsPage extends PredictListPage
{
    public const APPROVE_WARNING = 'Every bet on the market is settled again on the new result. Players paid on the old one can go below zero.';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Corrections';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'predictions/corrections';

    protected ?string $heading = 'Result corrections';

    protected ?string $subheading = 'Requested by one admin, approved by another.';

    public static function tabs(): array
    {
        return ['requested' => 'Waiting for approval', 'running' => 'Running', 'failed' => 'Failed', 'done' => 'Done', 'cancelled' => 'Cancelled', 'all' => 'All'];
    }

    protected function fetch(array $filters): array
    {
        return app(GameApiService::class)->listPredictCorrections($filters);
    }

    /**
     * Whether the signed-in staff member asked for this correction (and so may not approve it).
     *
     * @param  array<string, mixed>  $correction
     */
    public static function isOwnRequest(array $correction): bool
    {
        return ($correction['requested_by'] ?? null) === static::adminUser();
    }

    protected function configureTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Requested')->formatStateUsing(fn (?string $state): string => Format::dateTime($state)),
                TextColumn::make('market_id')
                    ->label('Market')
                    ->formatStateUsing(fn ($state): string => '#'.$state)
                    ->url(fn (array $record): ?string => static::marketUrl($record['market_id'] ?? null)),
                TextColumn::make('change')
                    ->label('Result')
                    ->state(fn (array $record): string => ucfirst((string) ($record['from_result'] ?? '?')).' → '.ucfirst((string) ($record['to_result'] ?? '?')))
                    ->weight('bold'),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        'requested' => 'warning',
                        'failed' => 'danger',
                        'done' => 'success',
                        default => 'gray',
                    })
                    ->description(fn (array $record): ?string => $record['error'] ?? null),
                TextColumn::make('requested_by')->label('By')->description(fn (array $record): ?string => isset($record['approved_by']) ? 'approved by '.$record['approved_by'] : null),
                TextColumn::make('wagers_corrected')->label('Wagers')->alignEnd()->placeholder('—'),
            ])
            ->recordActions([$this->approveAction(), $this->cancelAction(), $this->retryAction()]);
    }

    protected function approveAction(): Action
    {
        return Action::make('approveCorrection')
            ->label('Approve')
            ->icon('heroicon-o-check')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canSettlePredict() && ($record['status'] ?? null) === 'requested' && ! static::isOwnRequest($record))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => 'Approve correcting market #'.($record['market_id'] ?? '?').' to '.ucfirst((string) ($record['to_result'] ?? '')).'?')
            ->modalDescription(self::APPROVE_WARNING)
            ->modalSubmitActionLabel('Approve correction')
            ->action(fn (array $record, Action $action) => $this->correctionWrite($record, 'approve', [], $action, 'Correction approved', 'Could not approve the correction'));
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancelCorrection')
            ->label('Cancel')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->visible(fn (array $record): bool => static::canSettlePredict() && ($record['status'] ?? null) === 'requested')
            ->modalHeading('Cancel this correction')
            ->modalSubmitActionLabel('Cancel correction')
            ->schema([Textarea::make('reason')->required()->maxLength(255)->rows(2)])
            ->action(fn (array $record, array $data, Action $action) => $this->correctionWrite($record, 'cancel', ['reason' => trim($data['reason'])], $action, 'Correction cancelled', 'Could not cancel the correction'));
    }

    protected function retryAction(): Action
    {
        return Action::make('retryCorrection')
            ->label('Retry')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (array $record): bool => static::canSettlePredict() && ($record['status'] ?? null) === 'failed')
            ->requiresConfirmation()
            ->modalDescription('Only wagers not yet corrected are touched.')
            ->action(fn (array $record, Action $action) => $this->correctionWrite($record, 'retry', [], $action, 'Correction queued again', 'Could not retry the correction'));
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $extra
     */
    protected function correctionWrite(array $record, string $verb, array $extra, Action $action, string $done, string $failed): void
    {
        abort_unless(static::canSettlePredict(), 403);

        $id = (int) $record['id'];
        $payload = ['admin_user' => static::adminUser(), ...$extra];
        $result = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->predictCorrectionAction($id, $verb, $payload), 'PredictCorrection', $id, $verb, $payload, $failed, ['reason' => 'reason']);

        if ($result !== null) {
            Notification::make()->title($done)->success()->send();
            $this->afterPredictWrite();
        }
    }
}
