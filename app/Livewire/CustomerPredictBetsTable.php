<?php

namespace App\Livewire;

use App\Exceptions\GameApiException;
use App\Filament\Pages\PredictMarketDetailPage;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * A player's prediction market bets on the Kadi API. Read-only on the customer
 * page; staff with `predict.view` can open the market.
 */
class CustomerPredictBetsTable extends TableWidget
{
    public int $customerId;

    public bool $apiError = false;

    protected int|string|array $columnSpan = 'full';

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(null)
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => ApiTablePaginator::fromReport($this->fetchBets((int) $page, (int) $recordsPerPage)))
            ->columns([
                TextColumn::make('placed_at')->label('Placed')->formatStateUsing(fn (?string $state): string => Format::dateTime($state)),
                TextColumn::make('question')->label('Market')->wrap()
                    ->state(fn (array $record): ?string => $record['market']['question'] ?? $record['question'] ?? null)
                    ->description(fn (array $record): ?string => isset($record['side']) ? 'On '.ucfirst((string) $record['side']) : null),
                TextColumn::make('stake')->alignEnd()->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('multiplier')->label('Multiplier')->alignEnd()->formatStateUsing(fn ($state): string => number_format((float) $state, 2).'x')->placeholder('—'),
                TextColumn::make('payout')->alignEnd()->formatStateUsing(fn ($state): string => Format::money($state))->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
            ])
            ->recordUrl(function (array $record): ?string {
                $marketId = $record['market_id'] ?? $record['market']['id'] ?? null;

                return $marketId !== null && PredictMarketDetailPage::canAccess() ? PredictMarketDetailPage::getUrl(['market' => (int) $marketId]) : null;
            })
            ->paginated([10, 25, 50])
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Prediction bets unavailable' : 'No prediction bets');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchBets(int $page, int $perPage): array
    {
        try {
            $data = app(GameApiService::class)->listCustomerPredictBets($this->customerId, ['page' => max(1, $page), 'per_page' => min(200, max(1, $perPage))]);
            $this->apiError = false;
        } catch (GameApiException $e) {
            Log::warning('Customer prediction bets failed', ['customer' => $this->customerId, 'error' => $e->getMessage()]);
            $this->apiError = true;

            return [];
        }

        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['id']))
            ->map(fn (array $row): array => [...$row, ArrayRecord::getKeyName() => (string) $row['id']])
            ->values()
            ->all();

        return $data;
    }
}
