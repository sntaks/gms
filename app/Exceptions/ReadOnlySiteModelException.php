<?php

namespace App\Exceptions;

use LogicException;

/**
 * GMS tried to write the Kadi site's database directly. Site changes must go
 * through the signed back-office API (App\Services\SiteBackofficeClient).
 */
class ReadOnlySiteModelException extends LogicException
{
    public static function for(string $model, string $operation): self
    {
        return new self("[{$model}] is read-only: GMS never writes the Kadi site's database ({$operation} refused). Use the back-office API.");
    }
}
