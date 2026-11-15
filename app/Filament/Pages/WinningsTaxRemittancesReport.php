<?php

namespace App\Filament\Pages;

use App\Services\GameApiService;

/**
 * Winnings tax payments made to KRA. Recording one attaches every unremitted
 * charge and credit in its period; voiding one detaches them. Same flow as the
 * excise remittances; both writes need `winnings-tax.remit`.
 */
class WinningsTaxRemittancesReport extends ExciseRemittancesReport
{
    protected static ?string $navigationLabel = 'Winnings Tax Remittances';

    protected ?string $heading = 'Winnings Tax Remittances';

    public static function canRemit(): bool
    {
        $user = auth()->user();

        return ($user?->canViewFinanceReports() ?? false) && $user->hasPermissionTo('winnings-tax.remit');
    }

    protected function reportKey(): string
    {
        return 'winnings-tax/remittances';
    }

    protected function exportKey(): ?string
    {
        return null;
    }

    protected function returnsReportKey(): string
    {
        return 'winnings-tax/returns';
    }

    protected function recordDescription(): string
    {
        return 'Every unremitted winnings tax charge and credit in the period is attached to this payment.';
    }

    protected function recordRemittance(array $data, string $idempotencyKey): array
    {
        return app(GameApiService::class)->recordWinningsTaxRemittance($data, $idempotencyKey);
    }

    protected function voidRemittance(int $remittanceId, string $reason, string $idempotencyKey): array
    {
        return app(GameApiService::class)->voidWinningsTaxRemittance($remittanceId, $reason, $idempotencyKey);
    }
}
