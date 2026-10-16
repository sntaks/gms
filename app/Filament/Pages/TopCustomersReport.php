<?php

namespace App\Filament\Pages;

use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Livewire\Attributes\Url;

class TopCustomersReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Top Customers';

    protected static ?int $navigationSort = 25;

    protected ?string $heading = 'Top Customers';

    #[Url]
    public string $sort = 'net_gaming';

    /**
     * @return array<string, string>
     */
    public static function sortOptions(): array
    {
        return [
            'net_gaming' => 'Net gaming (won − staked)',
            'deposited' => 'Deposited',
            'staked' => 'Staked',
            'won' => 'Won',
            'withdrawn' => 'Withdrawn',
            'balance' => 'Wallet balance',
            'shop_loaded' => 'Cash loaded at shops',
            'shop_withdrawn' => 'Cash withdrawn at shops',
        ];
    }

    protected function reportKey(): string
    {
        return 'customers/top';
    }

    protected function exportKey(): ?string
    {
        return 'customers-top';
    }

    protected function extraFilters(): array
    {
        return [
            'sort' => array_key_exists($this->sort, self::sortOptions()) ? $this->sort : 'net_gaming',
            'limit' => 50,
        ];
    }

    protected function summaryStats(array $data): array
    {
        $summary = $data['summary'] ?? [];
        $concentration = $summary['concentration'] ?? [];

        return [
            ['label' => 'Customer Wallets', 'value' => Format::money($summary['customer_wallets_total'] ?? 0), 'description' => 'Total held for players', 'icon' => 'heroicon-m-wallet', 'color' => 'primary'],
            ['label' => 'Top '.($concentration['top_wallets'] ?? 10).' Wallets', 'value' => Format::money($concentration['balance'] ?? 0), 'description' => number_format((float) ($concentration['share_percent'] ?? 0), 1).'% of all wallet balances', 'icon' => 'heroicon-m-chart-pie', 'color' => ($concentration['share_percent'] ?? 0) > 50 ? 'warning' : 'info'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'Ranked by '.strtolower(self::sortOptions()[$this->sort] ?? 'net gaming'),
                'description' => 'Deposited and withdrawn include cash at bet shops. Shop loaded is gross, before excise duty, so it is more than the customer page\'s shop loads.',
                'headers' => ['#', 'Player', 'Deposited', 'Shop loaded', 'Withdrawn', 'Shop withdrawn', 'Staked', 'Won', 'Net gaming', 'Balance'],
                'rows' => collect($data['items'] ?? [])
                    ->values()
                    ->map(fn (array $row, int $index): array => [
                        (string) ($index + 1),
                        ($row['customer_name'] ?? '—').' (#'.($row['customer_id'] ?? '—').')',
                        Format::money($row['deposited'] ?? 0),
                        Format::money($row['shop_loaded'] ?? 0),
                        Format::money($row['withdrawn'] ?? 0),
                        Format::money($row['shop_withdrawn'] ?? 0),
                        Format::money($row['staked'] ?? 0),
                        Format::money($row['won'] ?? 0),
                        Format::money($row['net_gaming'] ?? 0),
                        Format::money($row['balance'] ?? 0),
                    ])
                    ->all(),
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sortBy')
                ->label('Rank by: '.(self::sortOptions()[$this->sort] ?? 'Net gaming'))
                ->icon('heroicon-o-bars-arrow-down')
                ->color('gray')
                ->modalHeading('Rank customers by')
                ->modalWidth('md')
                ->fillForm(fn (): array => ['sort' => $this->sort])
                ->schema([Select::make('sort')->options(self::sortOptions())->required()])
                ->action(function (array $data): void {
                    $this->sort = $data['sort'];
                    $this->onFilterApplied();
                }),
            ...parent::getHeaderActions(),
        ];
    }
}
