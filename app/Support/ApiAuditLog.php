<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

/**
 * Records a write the GMS sent to KadiApi in the audit log. KadiApi data has no
 * local model, so `auditable_type` names the remote record (for example
 * `KadiApi\ReferralWithdrawal`) and `new_values` holds the request payload, the
 * HTTP status and the response or error message. Failed writes are logged too.
 * Writes to the Kadi site's back-office API use the `KadiSite` prefix.
 */
class ApiAuditLog
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $response
     */
    public static function record(string $subject, int|string $subjectId, string $event, array $payload, int $status, array $response, string $system = 'KadiApi', ?int $userId = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'auditable_type' => $system.'\\'.$subject,
            'auditable_id' => (int) $subjectId,
            'event' => $event,
            'old_values' => null,
            'new_values' => [
                'payload' => $payload,
                'status' => $status,
                'response' => $response,
            ],
            'ip_address' => Request::ip(),
        ]);
    }
}
