<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\FinanceReports\FinanceReportsCluster;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use UnitEnum;

class ReconciliationReport extends FinanceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static ?int $navigationSort = 14;

    protected static string|UnitEnum|null $navigationGroup = FinanceReportsCluster::OVERVIEW;

    protected ?string $heading = 'Reconciliation';

    protected function reportKey(): string
    {
        return 'reconciliation';
    }

    /**
     * The check named `$key` in the report, when KadiApi ran it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function check(array $data, string $key): ?array
    {
        return collect($data['checks'] ?? [])->first(fn ($check): bool => is_array($check) && ($check['key'] ?? null) === $key);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('staleShopWithdrawals')
                ->label(fn (): string => 'Stale shop withdrawals: '.number_format((int) (static::check($this->getReport(), 'stale_shop_withdrawals')['count'] ?? 0)))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->tooltip('Pending cash withdrawals the expiry job should have released. Opens the pending list.')
                ->visible(fn (): bool => ShopWithdrawalsPage::canAccess() && (static::check($this->getReport(), 'stale_shop_withdrawals')['status'] ?? 'pass') !== 'pass')
                ->url(fn (): string => ShopWithdrawalsPage::getUrlForStatus('pending')),
            ...parent::getHeaderActions(),
        ];
    }

    protected function summaryStats(array $data): array
    {
        $status = (string) ($data['status'] ?? 'unknown');

        return [
            ['label' => 'Overall', 'value' => strtoupper($status), 'description' => 'Worst result across all controls', 'icon' => 'heroicon-m-shield-check', 'color' => self::statusColor($status)],
            ['label' => 'Passing', 'value' => (string) ($data['counts']['pass'] ?? 0), 'icon' => 'heroicon-m-check-circle', 'color' => 'success'],
            ['label' => 'Warnings', 'value' => (string) ($data['counts']['warn'] ?? 0), 'icon' => 'heroicon-m-exclamation-triangle', 'color' => 'warning'],
            ['label' => 'Failing', 'value' => (string) ($data['counts']['fail'] ?? 0), 'icon' => 'heroicon-m-x-circle', 'color' => 'danger'],
        ];
    }

    protected function blocks(array $data): array
    {
        return [
            [
                'title' => 'Controls',
                'description' => 'Failing controls first',
                'headers' => ['Status', 'Control', 'Items', 'Amount', 'Detail'],
                'rows' => collect($data['checks'] ?? [])
                    ->sortBy(fn (array $check): int => match ($check['status'] ?? '') {
                        'fail' => 0,
                        'warn' => 1,
                        default => 2,
                    })
                    ->map(fn (array $check): array => [
                        strtoupper((string) ($check['status'] ?? '—')),
                        (string) ($check['title'] ?? $check['key'] ?? '—'),
                        number_format((int) ($check['count'] ?? 0)),
                        Format::money($check['amount'] ?? 0),
                        (string) ($check['detail'] ?? ''),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'pass' => 'success',
            'warn' => 'warning',
            'fail' => 'danger',
            default => 'gray',
        };
    }
}
