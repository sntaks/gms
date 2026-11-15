<?php

namespace App\Concerns;

use App\Support\StaffIdentity;
use Filament\Actions\Action;
use Illuminate\Validation\ValidationException;

/**
 * Shared handling for staff writes to the Kadi API's prediction market admin
 * (`/predict/admin/*`).
 *
 * Those routes keep no idempotency record, so writes go through
 * {@see HandlesOneShotApiWrites}: never resent, audited, and reloaded after a
 * timeout. This adds the predict permissions and the staff `admin_user`.
 */
trait HandlesPredictWrites
{
    use HandlesOneShotApiWrites;

    /**
     * Refresh whatever shows the market after a write changed it, or may have.
     */
    abstract protected function afterPredictWrite(): void;

    public static function canViewPredict(): bool
    {
        return auth()->user()?->hasPermissionTo('predict.view') ?? false;
    }

    /**
     * Create, edit, publish, reject, suspend, resume, close, block and bulk actions.
     */
    public static function canManagePredict(): bool
    {
        return auth()->user()?->hasPermissionTo('predict.manage') ?? false;
    }

    /**
     * Resolve and void, proposals, corrections, settlement retries and risk events.
     */
    public static function canSettlePredict(): bool
    {
        return auth()->user()?->hasPermissionTo('predict.settle') ?? false;
    }

    /**
     * The signed-in staff member as the API's `admin_user`.
     */
    protected static function adminUser(): string
    {
        return StaffIdentity::adminUser(auth()->user());
    }

    /**
     * Run one prediction write; see {@see HandlesOneShotApiWrites::sendOneShotWrite()}.
     *
     * @param  callable(): array<string, mixed>  $send
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $fields
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    protected function sendPredictWrite(Action $action, callable $send, string $subject, int $subjectId, string $event, array $payload, string $failureTitle, array $fields = []): ?array
    {
        return $this->sendOneShotWrite($action, $send, $subject, $subjectId, $event, $payload, $failureTitle, $fields);
    }

    protected function afterOneShotWrite(): void
    {
        $this->afterPredictWrite();
    }
}
