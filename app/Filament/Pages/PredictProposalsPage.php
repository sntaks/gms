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
 * Polymarket results waiting for an admin (`GET /predict/admin/proposals`), with
 * automatic settlement off. Confirming resolves the market on the proposed
 * result and pays out at once; rejecting leaves it to be resolved by hand.
 * Both need `predict.settle`.
 */
class PredictProposalsPage extends PredictListPage
{
    public const CONFIRM_WARNING = 'Confirming resolves the market on this result and pays every winning bet at once.';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Proposals';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'predictions/proposals';

    protected ?string $heading = 'Resolution proposals';

    protected ?string $subheading = 'Results Polymarket reported for imported markets, waiting for an admin to confirm or reject.';

    public static function tabs(): array
    {
        return ['pending' => 'Waiting', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected'];
    }

    protected function fetch(array $filters): array
    {
        return app(GameApiService::class)->listPredictProposals($filters);
    }

    protected function configureTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Proposed')->formatStateUsing(fn (?string $state): string => Format::dateTime($state)),
                TextColumn::make('question')
                    ->weight('bold')
                    ->wrap()
                    ->url(fn (array $record): ?string => static::marketUrl($record['market_id'] ?? null))
                    ->description(fn (array $record): ?string => isset($record['market_status']) ? 'Market '.(config('predict.statuses.'.$record['market_status']) ?? $record['market_status']) : null),
                TextColumn::make('result')->label('Proposed result')->badge()->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                TextColumn::make('evidence')
                    ->state(fn (array $record): ?string => is_array($record['evidence'] ?? null) ? json_encode($record['evidence'], JSON_UNESCAPED_SLASHES) : ($record['evidence'] ?? null))
                    ->limit(80)
                    ->placeholder('—'),
                TextColumn::make('reviewed_by')
                    ->label('Reviewed')
                    ->placeholder('—')
                    ->description(fn (array $record): ?string => $record['review_reason'] ?? null),
            ])
            ->recordActions([$this->confirmAction(), $this->rejectAction()]);
    }

    protected function confirmAction(): Action
    {
        return Action::make('confirmProposal')
            ->label('Confirm')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (array $record): bool => static::canSettlePredict() && ($record['status'] ?? null) === 'pending')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => 'Confirm '.ucfirst((string) ($record['result'] ?? '')).' for: '.($record['question'] ?? 'market'))
            ->modalDescription(self::CONFIRM_WARNING)
            ->modalSubmitActionLabel('Confirm and pay out')
            ->action(function (array $record, Action $action): void {
                abort_unless(static::canSettlePredict(), 403);

                $id = (int) $record['id'];
                $payload = ['admin_user' => static::adminUser()];
                $result = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->predictProposalAction($id, 'confirm', $payload), 'PredictProposal', $id, 'confirm', $payload, 'Could not confirm the proposal');

                if ($result !== null) {
                    Notification::make()->title('Proposal confirmed')->body('The market is resolved and winning bets are being paid.')->success()->send();
                    $this->afterPredictWrite();
                }
            });
    }

    protected function rejectAction(): Action
    {
        return Action::make('rejectProposal')
            ->label('Reject')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (array $record): bool => static::canSettlePredict() && ($record['status'] ?? null) === 'pending')
            ->modalHeading('Reject this proposal')
            ->modalDescription('The market stays closed until it is resolved by hand.')
            ->modalSubmitActionLabel('Reject')
            ->schema([Textarea::make('reason')->required()->maxLength(255)->rows(2)])
            ->action(function (array $record, array $data, Action $action): void {
                abort_unless(static::canSettlePredict(), 403);

                $id = (int) $record['id'];
                $payload = ['admin_user' => static::adminUser(), 'reason' => trim($data['reason'])];
                $result = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->predictProposalAction($id, 'reject', $payload), 'PredictProposal', $id, 'reject', $payload, 'Could not reject the proposal', ['reason' => 'reason']);

                if ($result !== null) {
                    Notification::make()->title('Proposal rejected')->success()->send();
                    $this->afterPredictWrite();
                }
            });
    }
}
