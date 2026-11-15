<?php

namespace App\Concerns;

use App\Exceptions\GameApiException;
use App\Support\ApiAuditLog;
use App\Support\StaffIdentity;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Shared handling for staff writes to the Kadi API's prediction market admin
 * (`/predict/admin/*`).
 *
 * Those routes keep no idempotency record, so a write whose answer never came
 * back (timeout, 5xx) may still have run: the modal closes, the page reloads
 * the market, and staff are told to check its status before trying again.
 * Nothing is ever resent automatically. A 422 goes onto the form; a 409
 * refusal shows the API's message and refreshes; a 429 keeps the modal open,
 * since a rate-limited call never ran. Every attempt is written to the audit log.
 */
trait HandlesPredictWrites
{
    public const UNKNOWN_OUTCOME = 'The Kadi API did not answer, so it is not known whether this went through. The market has been reloaded: check its status before trying again.';

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
     * Run one write and audit it. Returns the API's data, or null when it was refused
     * or its outcome is unknown; the staff member has been told which.
     *
     * @param  callable(): array<string, mixed>  $send
     * @param  array<string, mixed>  $payload  What was sent, for the audit log.
     * @param  array<string, string>  $fields  API field => form field, for 422 errors.
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    protected function sendPredictWrite(Action $action, callable $send, string $subject, int $subjectId, string $event, array $payload, string $failureTitle, array $fields = []): ?array
    {
        try {
            $data = $send();
        } catch (GameApiException $e) {
            ApiAuditLog::record($subject, $subjectId, $event, $payload, $e->statusCode, array_filter([
                'message' => $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage(),
                'code' => $e->errorCode,
                'errors' => $e->errors,
            ]));

            $this->handlePredictWriteFailure($e, $action, $failureTitle, $fields);

            return null;
        }

        ApiAuditLog::record($subject, (int) ($data['id'] ?? $subjectId), $event, $payload, 200, ['status' => $data['status'] ?? null]);

        return $data;
    }

    /**
     * @param  array<string, string>  $fields
     *
     * @throws ValidationException
     */
    protected function handlePredictWriteFailure(GameApiException $e, Action $action, string $failureTitle, array $fields = []): void
    {
        $message = $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();

        if ($e->statusCode === 0 || $e->statusCode >= 500) {
            Notification::make()->title('Not confirmed')->body(self::UNKNOWN_OUTCOME)->warning()->persistent()->send();
            $this->afterPredictWrite();

            return;
        }

        if ($e->statusCode === 422) {
            $index = $action->getNestingIndex();
            $fieldErrors = Arr::only($e->errors, array_keys($fields));

            if ($fieldErrors !== []) {
                throw ValidationException::withMessages(collect($fieldErrors)
                    ->mapWithKeys(fn (array|string $messages, string $field): array => [
                        "mountedActions.{$index}.data.{$fields[$field]}" => Arr::wrap($messages),
                    ])
                    ->all());
            }

            Notification::make()->title($failureTitle)->body($message)->danger()->send();
            $action->halt();
        }

        if ($e->statusCode === 429) {
            Notification::make()->title($failureTitle)->body($message)->warning()->send();
            $action->halt();
        }

        Notification::make()
            ->title($e->statusCode === 404 ? 'Not found on the Kadi API' : $failureTitle)
            ->body($message)
            ->warning()
            ->send();

        $this->afterPredictWrite();
    }
}
