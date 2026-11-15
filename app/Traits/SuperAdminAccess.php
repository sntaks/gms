<?php

namespace App\Traits;

/**
 * Limits a page to super-admins and admins. Filament pages are gated by
 * canAccess(), so the trait provides it; canViewAny() stays for callers that use it.
 */
trait SuperAdminAccess
{
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'admin']) ?? false;
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }
}
