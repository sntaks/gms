<?php

namespace App\Concerns;

use App\Exceptions\GameApiException;
use App\Support\ApiAuditLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Staff writes to Kadi API routes that keep no idempotency record (prediction
 * market admin, wager void, games and products).
 *
 * A write whose answer never came back (timeout, 5xx) may still have run, so
 * the modal closes, the page reloads, and staff are told to check before trying
 * again: nothing is ever resent automatically. A 422 goes onto the form; a 404
 * or 409 refusal shows the API's message and refreshes; a 429 keeps the modal
 * open, since a rate-limited call never ran. Every attempt is audited.
 */
trait HandlesOneShotApiWrites
{
    public const UNKNOWN_OUTCOME = 'The Kadi API did not answer, so it is not known whether this went through. The page has been reloaded: check before trying again.';

    /**
     * Refresh whatever shows the record after a write changed it, or may have.
     */
    abstract protected function afterOneShotWrite(): void;

    /**
     * Run one write and audit it. Returns the API's data, or null when it was refused
     * or its outcome is unknown; the staff member has been told which.
     *
     * @param  callable(): array<string, mixed>  $send
     * @param  array<string, mixed>  $payload  What was sent, for the audit log (never a secret).
     * @param  array<string, string>  $fields  API field => form field, for 422 errors.
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    protected function sendOneShotWrite(Action $action, callable $send, string $subject, int $subjectId, string $event, array $payload, string $failureTitle, array $fields = []): ?array
    {
        try {
            $data = $send();
        } catch (GameApiException $e) {
            ApiAuditLog::record($subject, $subjectId, $event, $payload, $e->statusCode, array_filter([
                'message' => $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage(),
                'code' => $e->errorCode,
                'errors' => $e->errors,
            ]));

            $this->handleOneShotFailure($e, $action, $failureTitle, $fields);

            return null;
        }

        ApiAuditLog::record($subject, (int) (is_numeric($data['id'] ?? null) ? $data['id'] : $subjectId), $event, $payload, 200, array_filter(['status' => $data['status'] ?? null]));

        return $data;
    }

    /**
     * @param  array<string, string>  $fields
     *
     * @throws ValidationException
     */
    protected function handleOneShotFailure(GameApiException $e, Action $action, string $failureTitle, array $fields = []): void
    {
        $message = $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();

        if ($e->statusCode === 0 || $e->statusCode >= 500) {
            Notification::make()->title('Not confirmed')->body(self::UNKNOWN_OUTCOME)->warning()->persistent()->send();
            $this->afterOneShotWrite();

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

        $this->afterOneShotWrite();
    }
}
