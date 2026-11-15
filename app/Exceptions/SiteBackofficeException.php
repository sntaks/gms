<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A call to the Kadi site's back-office API that did not succeed.
 *
 * `statusCode` is 0 when no answer came back (timeout, connection refused) or the
 * GMS has no signing secret. A retry of a write must reuse `requestId` so the
 * site runs the action at most once.
 */
class SiteBackofficeException extends RuntimeException
{
    /**
     * @param  array<string, list<string>|string>  $errors  Field errors from a 422 response.
     * @param  string|null  $errorCode  The site's `code`, e.g. `market_cannot_be_reopened`.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly string $siteMessage = '',
        public readonly array $errors = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Whether the action may still have run (no answer came back, or the site is
     * still handling the first call), so the only safe next step is a retry with
     * the same request id. A refusal (401, 404, 409 with a code, 422, 429, 5xx)
     * changed nothing on the site.
     */
    public function outcomeUnknown(): bool
    {
        if ($this->requestId === null) {
            return false;
        }

        return $this->statusCode === 0 || ($this->statusCode === 409 && $this->errorCode === null);
    }
}
