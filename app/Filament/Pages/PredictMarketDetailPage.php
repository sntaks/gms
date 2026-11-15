<?php

namespace App\Filament\Pages;

use App\Concerns\HandlesPredictWrites;
use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use App\Support\Format;
use App\Support\PredictMarketForm;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * One Kadi API prediction market and its lifecycle: draft → open ⇄ suspended →
 * closed → resolved → settled, or voided, or a rejected draft. Admins
 * (`predict.manage`) edit, publish, reject, suspend, resume and close; finance
 * (`predict.settle`) resolves (paying out at once) and voids. The predict admin
 * routes keep no idempotency record, so nothing here is ever resent on its own.
 */
class PredictMarketDetailPage extends Page
{
    use HandlesPredictWrites;

    public const RESOLVE_WARNING = 'Resolving pays every winning bet at once; there is no dispute window. A wrong result can only be fixed by a correction, which needs a second admin.';

    public const VOID_WARNING = 'Voiding refunds every stake and is final.';

    /**
     * Lifecycle actions and the statuses they apply to (api MarketService transitions).
     *
     * @var array<string, list<string>>
     */
    public const ACTION_STATUSES = [
        'publish' => ['draft'],
        'reject' => ['draft'],
        'suspend' => ['open'],
        'resume' => ['suspended'],
        'close' => ['open', 'suspended'],
        'resolve' => ['closed'],
        'void' => ['draft', 'open', 'suspended', 'closed'],
    ];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'predictions/markets/detail';

    protected string $view = 'filament.pages.predict-market-detail-page';

    #[Url]
    public ?int $market = null;

    /**
     * The market as the API last returned it. Locked so the browser cannot change
     * what the page's actions are offered for.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $details = [];

    public bool $apiError = false;

    public static function canAccess(): bool
    {
        return static::canViewPredict();
    }

    public function mount(): void
    {
        abort_if($this->market === null, 404);

        $this->loadMarket();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->details['question'] ?? 'Prediction market';
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [PredictMarketsPage::getUrl() => 'Markets', 'Market'];
    }

    protected function loadMarket(): void
    {
        try {
            $this->details = app(GameApiService::class)->getPredictMarket((int) $this->market);
            $this->apiError = false;
        } catch (GameApiException $e) {
            abort_if($e->statusCode === 404, 404);

            Log::warning('Prediction market lookup failed', ['market' => $this->market, 'error' => $e->getMessage()]);
            $this->apiError = true;
        }
    }

    protected function afterPredictWrite(): void
    {
        $this->loadMarket();
    }

    public function status(): ?string
    {
        return $this->details['status'] ?? null;
    }

    public function sideLabel(string $side): string
    {
        return $this->details['odds'][$side.'_label'] ?? ucfirst($side);
    }

    public function marketInfolist(Schema $schema): Schema
    {
        $market = $this->details;
        $odds = $market['odds'] ?? null;
        $pool = $market['pool'] ?? null;
        $exposure = $market['exposure'] ?? null;
        $external = $market['external'] ?? null;
        $percent = fn (mixed $value): ?string => $value === null ? null : rtrim(rtrim(number_format(((float) $value) * 100, 2), '0'), '.').'%';

        return $schema
            ->constantState([
                'status' => $market['status'] ?? null,
                'result' => isset($market['result']) ? $this->sideLabel((string) $market['result']) : null,
                'kind' => implode(' · ', array_filter([
                    config('predict.sources.'.($market['source'] ?? '')),
                    config('predict.engines.'.($market['engine'] ?? '')),
                    config('predict.categories.'.($market['category'] ?? '')),
                ])),
                'close_at' => Format::dateTime($market['close_at'] ?? null),
                'betting_closes_at' => Format::dateTime($market['betting_closes_at'] ?? null),
                'resolution_deadline_at' => Format::dateTime($market['resolution_deadline_at'] ?? null),
                'yes' => $odds === null ? null : $this->sideLine('yes', $odds),
                'no' => $odds === null ? null : $this->sideLine('no', $odds),
                'pool' => $pool === null ? null : 'Yes '.Format::money($pool['yes'] ?? 0).' · No '.Format::money($pool['no'] ?? 0)
                    .' (seed '.Format::money(($pool['seed_yes'] ?? 0) + ($pool['seed_no'] ?? 0)).', notional)',
                'staked' => $odds === null ? null : Format::money($odds['volume'] ?? 0).' on '.($odds['bets_count'] ?? 0).' bets',
                'terms' => implode(' · ', array_filter([
                    isset($market['cut']) ? 'Cut '.$percent($market['cut']) : null,
                    isset($market['min_multiplier']) ? 'Min multiplier '.number_format((float) $market['min_multiplier'], 2).'x' : null,
                    isset($market['start_probability']) ? 'Opened at '.$percent($market['start_probability']).' Yes' : null,
                    isset($market['liquidity']) ? 'Liquidity '.Format::money($market['liquidity']) : null,
                ])),
                'limits' => 'Stake '.Format::money($market['min_stake'] ?? 0).' – '.Format::money($market['max_stake'] ?? 0)
                    .' · max payout '.Format::money($market['max_payout'] ?? 0),
                'risk' => implode(' · ', array_filter([
                    isset($market['worst_case_loss']) ? 'Worst-case loss '.Format::money($market['worst_case_loss']) : null,
                    $exposure === null ? null : 'House result if Yes '.Format::money($exposure['yes']['house_result_if_wins'] ?? 0)
                        .', if No '.Format::money($exposure['no']['house_result_if_wins'] ?? 0),
                    $exposure === null ? null : 'Margin '.$percent($market['margin'] ?? 0).', max liability '.Format::money($market['max_liability'] ?? 0)
                        .', max per customer '.Format::money($market['max_customer_stake'] ?? 0),
                ])) ?: null,
                'resolution_criteria' => $market['resolution_criteria'] ?? null,
                'description' => $market['description'] ?? null,
                'resolution_source' => $market['resolution_source'] ?? null,
                'external' => $external === null ? null : implode(' · ', array_filter([
                    isset($external['volume']) ? 'Volume USD '.Format::compact($external['volume']) : null,
                    isset($external['spread']) ? 'Spread '.$external['spread'] : null,
                    isset($external['synced_at']) ? 'Synced '.Format::dateTime($external['synced_at']) : null,
                    ($external['closed'] ?? false) ? 'Closed on Polymarket' : null,
                ])),
                'external_url' => $market['external_url'] ?? null,
                'history' => implode(' · ', array_filter([
                    isset($market['created_by']) ? 'Created by '.$market['created_by'] : null,
                    isset($market['opened_at']) ? 'opened '.Format::dateTime($market['opened_at']) : null,
                    isset($market['resolved_by']) ? 'resolved by '.$market['resolved_by'] : null,
                    isset($market['reject_reason']) ? 'rejected: '.$market['reject_reason'] : null,
                    isset($market['void_reason']) ? 'voided: '.$market['void_reason'] : null,
                ])),
            ])
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?string $state): string => PredictMarketsPage::statusColor($state))
                            ->formatStateUsing(fn (?string $state): string => config('predict.statuses.'.$state) ?? ucfirst((string) $state)),
                        TextEntry::make('result')->placeholder('Not resolved'),
                        TextEntry::make('kind')->label('Market'),
                        TextEntry::make('close_at')->label('Closes'),
                        TextEntry::make('betting_closes_at')->label('Betting closes'),
                        TextEntry::make('resolution_deadline_at')->label('Resolve by'),
                    ]),
                Section::make('Odds')
                    ->description(($market['engine'] ?? null) === 'pool' ? 'Estimated multipliers: the pool moves with every bet.' : null)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('yes')->label($this->sideLabel('yes'))->placeholder('—'),
                        TextEntry::make('no')->label($this->sideLabel('no'))->placeholder('—'),
                        TextEntry::make('pool')->label('Pool')->placeholder('—'),
                        TextEntry::make('staked')->label('Staked by players')->placeholder('—'),
                    ]),
                Section::make('Terms')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('terms')->label('Pricing')->placeholder('—'),
                        TextEntry::make('limits')->label('Limits'),
                        TextEntry::make('risk')->label('Risk')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Wording')
                    ->schema([
                        TextEntry::make('resolution_criteria')->label('Resolves Yes when')->placeholder('—'),
                        TextEntry::make('description')->placeholder('—'),
                        TextEntry::make('resolution_source')->label('Resolution source')->placeholder('—'),
                    ]),
                Section::make('Polymarket')
                    ->visible($external !== null)
                    ->schema([
                        TextEntry::make('external')->hiddenLabel(),
                        TextEntry::make('external_url')->label('On Polymarket')->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—'),
                    ]),
                Section::make('History')
                    ->schema([TextEntry::make('history')->hiddenLabel()->placeholder('—')]),
            ]);
    }

    /**
     * "61% · 1.55x (estimated)".
     *
     * @param  array<string, mixed>  $odds
     */
    protected function sideLine(string $side, array $odds): ?string
    {
        $probability = $odds[$side.'_probability'] ?? null;
        $multiplier = $odds[$side.'_multiplier'] ?? null;

        if ($probability === null && $multiplier === null) {
            return null;
        }

        return implode(' · ', array_filter([
            $probability === null ? null : round(((float) $probability) * 100).'%',
            $multiplier === null ? null : number_format((float) $multiplier, 2).'x'.(($odds['is_estimate'] ?? false) ? ' (estimated)' : ''),
        ]));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->editMarketAction(),
            $this->lifecycleAction('publish', 'Publish', 'heroicon-o-rocket-launch', 'success', manage: true,
                description: 'Players can bet on it at once. An imported market opens at its Polymarket price, read now; with no usable price it is refused.'),
            $this->lifecycleAction('suspend', 'Suspend', 'heroicon-o-pause-circle', 'warning', manage: true,
                description: 'Betting stops until it is resumed. Bets already placed stand.'),
            $this->lifecycleAction('resume', 'Resume', 'heroicon-o-play-circle', 'success', manage: true),
            $this->lifecycleAction('close', 'Close', 'heroicon-o-lock-closed', 'gray', manage: true,
                description: 'Betting ends for good; the market then waits for its result.'),
            $this->resolveMarketAction(),
            $this->lifecycleAction('reject', 'Reject', 'heroicon-o-x-mark', 'danger', manage: true, reason: true,
                description: PredictMarketsPage::BULK_REJECT_WARNING),
            $this->lifecycleAction('void', 'Void', 'heroicon-o-no-symbol', 'danger', manage: false, reason: true,
                description: self::VOID_WARNING),
            Action::make('reload')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->loadMarket()),
        ];
    }

    public function isActionAvailable(string $action): bool
    {
        return in_array($this->status(), self::ACTION_STATUSES[$action] ?? [], true);
    }

    protected function lifecycleAction(string $verb, string $label, string $icon, string $color, bool $manage, bool $reason = false, ?string $description = null): Action
    {
        $allowed = fn (): bool => $manage ? static::canManagePredict() : static::canSettlePredict();

        return Action::make($verb.'Market')
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (): bool => $allowed() && $this->isActionAvailable($verb))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $label.': '.$this->getTitle())
            ->modalDescription($description)
            ->modalSubmitActionLabel($label)
            ->schema($reason ? [Textarea::make('reason')->required()->maxLength(255)->rows(2)] : [])
            ->action(function (array $data, Action $action) use ($verb, $label, $allowed): void {
                abort_unless($allowed(), 403);

                $id = (int) $this->market;
                $payload = array_filter(['admin_user' => static::adminUser(), 'reason' => isset($data['reason']) ? trim($data['reason']) : null]);
                $result = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->predictMarketAction($id, $verb, $payload), 'PredictMarket', $id, $verb, $payload, 'Could not '.strtolower($label), ['reason' => 'reason']);

                if ($result !== null) {
                    Notification::make()->title('Market: '.(config('predict.statuses.'.($result['status'] ?? '')) ?? 'done'))->success()->send();
                    $this->afterPredictWrite();
                }
            });
    }

    protected function resolveMarketAction(): Action
    {
        return Action::make('resolveMarket')
            ->label('Resolve')
            ->icon('heroicon-o-check-badge')
            ->color('primary')
            ->visible(fn (): bool => static::canSettlePredict() && $this->isActionAvailable('resolve'))
            ->modalHeading(fn (): string => 'Resolve: '.$this->getTitle())
            ->modalDescription(self::RESOLVE_WARNING)
            ->modalSubmitActionLabel('Resolve and pay out')
            ->schema(fn (): array => [
                Radio::make('result')
                    ->label('Result')
                    ->options(['yes' => $this->sideLabel('yes'), 'no' => $this->sideLabel('no')])
                    ->required(),
                Textarea::make('confirm_note')
                    ->label('Resolves Yes when')
                    ->default($this->details['resolution_criteria'] ?? null)
                    ->disabled()
                    ->dehydrated(false),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canSettlePredict(), 403);

                $id = (int) $this->market;
                $payload = ['admin_user' => static::adminUser(), 'result' => $data['result']];
                $result = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->predictMarketAction($id, 'resolve', $payload), 'PredictMarket', $id, 'resolve', $payload, 'Could not resolve', ['result' => 'result']);

                if ($result !== null) {
                    Notification::make()->title('Resolved: '.$this->sideLabel((string) $data['result']))->body('Winning bets are being paid.')->success()->send();
                    $this->afterPredictWrite();
                }
            });
    }

    protected function editMarketAction(): Action
    {
        return Action::make('editMarket')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => static::canManagePredict() && in_array($this->status(), ['draft', 'open', 'suspended'], true))
            ->modalHeading(fn (): string => $this->status() === 'draft' ? 'Edit draft' : 'Edit live market')
            ->modalDescription(fn (): ?string => $this->status() === 'draft' ? null : 'Pricing terms and the resolution criteria are frozen once published.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('3xl')
            ->fillForm(fn (): array => PredictMarketForm::fill($this->details))
            ->schema(fn (): array => $this->status() === 'draft'
                ? PredictMarketForm::draftFields(creating: false, imported: ($this->details['source'] ?? null) === 'polymarket')
                : PredictMarketForm::liveFields(quoted: ($this->details['exposure'] ?? null) !== null))
            ->action(function (array $data, Action $action): void {
                abort_unless(static::canManagePredict(), 403);

                $id = (int) $this->market;
                $fields = PredictMarketForm::payload($data, $this->status() === 'draft' ? null : PredictMarketForm::LIVE_FIELDS);
                unset($fields['engine']);
                $payload = ['admin_user' => static::adminUser(), ...$fields];
                $result = $this->sendPredictWrite($action, fn (): array => app(GameApiService::class)->updatePredictMarket($id, $payload), 'PredictMarket', $id, 'update', $payload, 'Could not save the market', PredictMarketForm::FIELDS);

                if ($result !== null) {
                    Notification::make()->title('Market saved')->success()->send();
                    $this->afterPredictWrite();
                }
            });
    }
}
