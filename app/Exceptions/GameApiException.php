<?php

namespace App\Exceptions;

use RuntimeException;

class GameApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>|string>  $errors  Field errors from a 422 response.
     * @param  string|null  $errorCode  KadiApi's machine-readable `code`, when the response has one.
     * @param  array<string, mixed>  $body  The whole decoded error response, for refusals that carry more (e.g. `float_balance`).
     * @param  int|null  $retryAfter  Seconds to wait before retrying, from a 429's `Retry-After` header.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly string $apiMessage = '',
        public readonly array $errors = [],
        public readonly ?string $errorCode = null,
        public readonly array $body = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
