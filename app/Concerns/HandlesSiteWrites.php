<?php

namespace App\Concerns;

use App\Exceptions\SiteBackofficeException;
use App\Services\SiteBackofficeClient;
use App\Support\SiteBackofficeResult;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared handling for every staff action GMS sends to the Kadi site's signed
 * back-office API (kadi docs/backoffice-api.md).
 *
 * Each write modal carries a request id created when it opens. If no answer
 * comes back, or the site is still handling the first call, the modal stays
 * open and submitting again resends the same request id, so the site runs the
 * action at most once. A 422 shows the site's message (on the form field when
 * it names one) and takes a fresh id, because the corrected form is a new
 * request. A 404 or 409 refusal closes the modal and refreshes the page. The
 * client writes every attempt to the audit log.
 */
trait HandlesSiteWrites
{
    /**
     * Refresh whatever shows the record after a write changed it, or found it changed.
     */
    abstract protected function afterSiteWrite(): void;

    /**
     * Suspensions, reopening, price overrides, competition settings and jackpot drafts.
     */
    public static function canTrade(): bool
    {
        return auth()->user()?->hasPermissionTo('sportsbook.trade') ?? false;
    }

    /**
     * Results, voided legs, cancelled bets and wager-op retries.
     */
    public static function canSettle(): bool
    {
        return auth()->user()?->hasPermissionTo('sportsbook.settle') ?? false;
    }

    /**
     * The hidden request id every write modal carries.
     */
    protected static function requestIdField(): Hidden
    {
        return Hidden::make('request_id');
    }

    /**
     * Data to open a write modal with: a fresh request id, plus any defaults.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    protected static function newSiteRequest(array $defaults = []): array
    {
        return ['request_id' => (string) Str::uuid(), ...$defaults];
    }

    /**
     * Send one write as the signed-in staff member. Returns null when the site
     * refused it or could not be reached; the staff member has been told why.
     *
     * @param  array<string, mixed>  $payload  The body, without `staff`.
     * @param  array<string, mixed>  $data  The modal's data, holding `request_id`.
     * @param  array<string, string>  $fields  Site field => form field, for 422 errors.
     *
     * @throws ValidationException
     */
    protected function sendSiteWrite(
        Action $action,
        array $data,
        string $method,
        string $path,
        array $payload,
        string $subject,
        int $subjectId,
        string $event,
        string $failureTitle,
        array $fields = [],
    ): ?SiteBackofficeResult {
        $requestId = is_string($data['request_id'] ?? null) && $data['request_id'] !== '' ? $data['request_id'] : null;

        try {
            return app(SiteBackofficeClient::class)->write($method, $path, $payload, auth()->user(), $subject, $subjectId, $event, $requestId);
        } catch (SiteBackofficeException $e) {
            $this->handleSiteWriteFailure($e, $action, $failureTitle, $fields);

            return null;
        }
    }

    /**
     * @param  array<string, string>  $fields
     *
     * @throws ValidationException
     */
    protected function handleSiteWriteFailure(SiteBackofficeException $e, Action $action, string $failureTitle, array $fields = []): void
    {
        $index = $action->getNestingIndex();

        if ($e->outcomeUnknown()) {
            Notification::make()
                ->title('Not confirmed yet')
                ->body($e->getMessage().' Submit again to retry: it is sent with the same request id.')
                ->warning()
                ->persistent()
                ->send();

            $action->halt();
        }

        if ($e->statusCode === 422) {
            if (array_key_exists('request_id', $this->mountedActions[$index]['data'] ?? [])) {
                $this->mountedActions[$index]['data']['request_id'] = (string) Str::uuid();
            }

            $fieldErrors = Arr::only($e->errors, array_keys($fields));

            if ($fieldErrors !== []) {
                throw ValidationException::withMessages(collect($fieldErrors)
                    ->mapWithKeys(fn (array|string $messages, string $field): array => [
                        "mountedActions.{$index}.data.{$fields[$field]}" => Arr::wrap($messages),
                    ])
                    ->all());
            }

            Notification::make()->title($failureTitle)->body($e->getMessage())->danger()->send();

            $action->halt();
        }

        if ($e->statusCode === 429) {
            Notification::make()->title($failureTitle)->body($e->getMessage())->warning()->send();

            $action->halt();
        }

        if (in_array($e->statusCode, [404, 409], true)) {
            Notification::make()
                ->title($e->statusCode === 404 ? 'Not found on the Kadi site' : $failureTitle)
                ->body($e->getMessage())
                ->warning()
                ->send();

            $this->afterSiteWrite();

            return;
        }

        Notification::make()->title($failureTitle)->body($e->getMessage())->danger()->send();
    }
}
