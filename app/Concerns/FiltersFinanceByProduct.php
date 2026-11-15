<?php

namespace App\Concerns;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;

/**
 * A product picker for finance reports the Kadi API can narrow to one product
 * (FinanceReportRequest::PRODUCT_FILTERED_ROUTES: income statement, house-banked,
 * ledger, adjustments, top customers, trial balance, winnings tax charges).
 */
trait FiltersFinanceByProduct
{
    #[Url]
    public ?string $product = null;

    /**
     * @return array<string, string> Product code => "Name (game)".
     */
    public static function productOptions(): array
    {
        return Cache::remember('finance:product-options', 600, function (): array {
            try {
                return collect(app(GameApiService::class)->listProducts())
                    ->filter(fn ($product): bool => is_array($product) && isset($product['code']))
                    ->mapWithKeys(fn (array $product): array => [$product['code'] => ($product['name'] ?? $product['code']).' ('.($product['game'] ?? '?').')'])
                    ->all();
            } catch (GameApiException) {
                return [];
            }
        });
    }

    protected function productFilterAction(): Action
    {
        return Action::make('filterProduct')
            ->label(fn (): string => $this->product === null ? 'All products' : (static::productOptions()[$this->product] ?? $this->product))
            ->icon('heroicon-o-cube')
            ->color('gray')
            ->modalHeading('Product')
            ->modalDescription('Narrow the report to one product, or show them all.')
            ->modalSubmitActionLabel('Apply')
            ->fillForm(fn (): array => ['product' => $this->product])
            ->schema([
                Select::make('product')->options(fn (): array => static::productOptions())->placeholder('All products')->searchable(),
            ])
            ->action(function (array $data): void {
                $this->product = filled($data['product'] ?? null) ? (string) $data['product'] : null;
                $this->cachedReport = null;
                $this->onFilterApplied();
            });
    }
}
