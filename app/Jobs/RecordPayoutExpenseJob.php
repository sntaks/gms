<?php

namespace App\Jobs;

use App\Enums\PayoutStatus;
use App\Exceptions\GameApiException;
use App\Models\Payout;
use App\Services\GameApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Record a completed payout as a company expense in the Kadi API's ledger, so the
 * income statement counts it (owner decision 2026-11-16). Run when M-Pesa
 * confirms the payout, not at approval, since an approved payout can still fail.
 *
 * Safe to run more than once: the reference `GMS-PAYOUT-{id}` may be used only
 * once among active expenses, and the Idempotency-Key is fixed per payout. A
 * failure is kept on the payout so staff can retry it from the payouts list.
 */
class RecordPayoutExpenseJob implements ShouldQueue
{
    use Queueable;

    public const CATEGORY = 'other';

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public int $payoutId) {}

    public static function reference(int $payoutId): string
    {
        return 'GMS-PAYOUT-'.$payoutId;
    }

    public function handle(GameApiService $api): void
    {
        $payout = Payout::with('payee')->find($this->payoutId);

        if ($payout === null || $payout->status !== PayoutStatus::Completed || $payout->api_expense_id !== null) {
            return;
        }

        $payeeName = $payout->payee?->name ?? 'payee';
        $designation = $payout->payee?->designation;

        try {
            $expense = $api->recordExpense([
                'expense_date' => ($payout->processed_at ?? now())->timezone(config('app.timezone'))->toDateString(),
                'category' => self::CATEGORY,
                'amount' => round((float) $payout->amount, 2),
                'description' => Str::limit('Payout to '.$payeeName.($designation ? " ({$designation})" : '').' — '.$payout->reason, 255, ''),
                'reference' => self::reference($payout->id),
            ], 'gms-payout-expense-'.$payout->id);
        } catch (GameApiException $e) {
            $message = $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();
            $payout->forceFill(['api_expense_error' => Str::limit($message, 255, '')])->saveQuietly();
            Log::warning('Payout expense not recorded in the Kadi API', ['payout' => $payout->id, 'status' => $e->statusCode, 'error' => $message]);

            // Retry only what may pass later: outages and rate limits, not refusals.
            if ($e->statusCode === 0 || $e->statusCode === 429 || $e->statusCode >= 500) {
                throw $e;
            }

            return;
        }

        $payout->forceFill(['api_expense_id' => (int) ($expense['id'] ?? 0) ?: null, 'api_expense_error' => null])->saveQuietly();
    }
}
