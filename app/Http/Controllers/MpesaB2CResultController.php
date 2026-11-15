<?php

namespace App\Http\Controllers;

use App\Enums\CompanyWithdrawStatus;
use App\Enums\PayoutStatus;
use App\Enums\WithdrawStatus;
use App\Jobs\RecordPayoutExpenseJob;
use App\Models\CompanyWithdraw;
use App\Models\Payout;
use App\Models\Withdraw;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaB2CResultController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::info('M-Pesa B2C Result received', $payload);

        $result = $payload['Result'] ?? [];
        $conversationId = $result['ConversationID'] ?? null;
        $resultCode = $result['ResultCode'] ?? -1;
        $resultDesc = $result['ResultDesc'] ?? '';

        if (! $conversationId) {
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        $newStatus = $resultCode == 0
            ? WithdrawStatus::Completed->value
            : WithdrawStatus::Failed->value;

        $withdraw = Withdraw::where('receipt', $conversationId)
            ->orWhere('conversation_id', $conversationId)
            ->first();

        if ($withdraw) {
            $withdraw->update(['status' => $newStatus, 'response' => $resultDesc]);
        }

        $companyWithdrawStatus = $resultCode == 0
            ? CompanyWithdrawStatus::Completed->value
            : CompanyWithdrawStatus::Failed->value;

        $companyWithdraw = CompanyWithdraw::where('receipt', $conversationId)
            ->orWhere('conversation_id', $conversationId)
            ->first();

        if ($companyWithdraw) {
            $companyWithdraw->update(['status' => $companyWithdrawStatus, 'response' => $resultDesc]);
        }

        $payoutStatus = $resultCode == 0
            ? PayoutStatus::Completed->value
            : PayoutStatus::Failed->value;

        $payout = Payout::where('receipt', $conversationId)
            ->orWhere('conversation_id', $conversationId)
            ->first();

        if ($payout) {
            $payout->update([
                'status' => $payoutStatus,
                'response' => $resultDesc,
                ...($resultCode == 0 ? ['processed_at' => now()] : []),
            ]);

            // A paid payout is a company expense in the Kadi API's ledger.
            if ($resultCode == 0) {
                RecordPayoutExpenseJob::dispatch($payout->id);
            }
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
