<?php

namespace App\Support;

/**
 * A successful answer from the Kadi site's back-office API.
 */
final readonly class SiteBackofficeResult
{
    /**
     * @param  array<string, mixed>  $data  The response's `data`.
     * @param  bool  $replayed  The site answered from its store (`Backoffice-Replayed: true`): the action ran earlier, not now.
     */
    public function __construct(
        public array $data,
        public int $status,
        public string $requestId,
        public bool $replayed = false,
    ) {}
}
