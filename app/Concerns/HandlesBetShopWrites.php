<?php

namespace App\Concerns;

use App\Exceptions\GameApiException;
use App\Support\ApiAuditLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared handling for every write the GMS sends to KadiApi's bet shop endpoints.
 *
 * Write modals carry an Idempotency-Key generated when they open, so a double click
 * or a retry after a rate limit or outage replays the same attempt. A 422 lands on
 * the form fields with a fresh key, because the corrected form is a new request. A
 * 404 or 409 refusal closes the modal and refreshes, because there is nothing to
 * retry. Every attempt is written to the audit log, with any issued shop key or
 * pickup code removed first.
 */
trait HandlesBetShopWrites
{
    /**
     * Response fields that must never reach the audit log: a shop API key is shown
     * once and never kept, and pickup codes belong to the player alone.
     *
     * @var list<string>
     */
    public const SECRET_FIELDS = ['key', 'pickup_code'];

    /**
     * Refresh whatever shows the shop after a write changed it, or found it changed.
     */
    abstract protected function afterBetShopWrite(): void;

    public static function canManageBetShops(): bool
    {
        return auth()->user()?->hasPermissionTo('bet-shops.manage') ?? false;
    }

    /**
     * Write one attempt to the audit log without any secret it carries.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $response
     */
    protected static function recordBetShopWrite(string $subject, int|string $subjectId, string $event, array $payload, int $status, array $response): void
    {
        ApiAuditLog::record($subject, $subjectId, $event, static::withoutSecrets($payload), $status, static::withoutSecrets($response));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function withoutSecrets(array $values): array
    {
        return collect($values)
            ->except(self::SECRET_FIELDS)
            ->map(fn ($value) => is_array($value) ? static::withoutSecrets($value) : $value)
            ->all();
    }

    /**
     * @return array{message: string, errors?: array<string, mixed>, code?: string}
     */
    protected static function betShopErrorResponse(GameApiException $e): array
    {
        return array_filter([
            'message' => static::betShopErrorMessage($e),
            'errors' => $e->errors,
            'code' => $e->errorCode,
        ], fn ($value): bool => $value !== null && $value !== []);
    }

    protected static function betShopErrorMessage(GameApiException $e): string
    {
        return $e->apiMessage !== '' ? $e->apiMessage : $e->getMessage();
    }

    /**
     * Show a failed write the way its status calls for.
     *
     * @param  string  $failureTitle  e.g. "Could not suspend the shop"
     * @param  array<string, string>  $fields  KadiApi field => form field, for 422 errors
     * @param  array<string, string>  $refusalTitles  KadiApi `code` => notification title, for 409 refusals
     *
     * @throws ValidationException
     */
    protected function handleBetShopWriteFailure(GameApiException $e, Action $action, string $failureTitle, array $fields = [], array $refusalTitles = []): void
    {
        $message = static::betShopErrorMessage($e);
        $index = $action->getNestingIndex();

        if ($e->statusCode === 422) {
            // The corrected form is a new request, so it must not replay this one's key.
            if (array_key_exists('idempotency_key', $this->mountedActions[$index]['data'] ?? [])) {
                $this->mountedActions[$index]['data']['idempotency_key'] = Str::uuid()->toString();
            }

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

        if (in_array($e->statusCode, [404, 409], true)) {
            Notification::make()
                ->title($refusalTitles[$e->errorCode ?? ''] ?? ($e->statusCode === 404 ? 'Not found' : $failureTitle))
                ->body($message)
                ->warning()
                ->send();

            $this->afterBetShopWrite();

            return;
        }

        Notification::make()->title($failureTitle)->body($message)->danger()->send();

        // Rate limits, outages and server errors keep the modal open so a retry reuses the same key.
        if ($e->statusCode === 0 || $e->statusCode === 429 || $e->statusCode >= 500) {
            $action->halt();
        }
    }
}
