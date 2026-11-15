<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * How a GMS staff member is named to the systems GMS acts on.
 *
 * Neither the Kadi API nor the Kadi site knows GMS users: they trust the name
 * GMS sends, so it must be the signed-in user and never typed in.
 */
final class StaffIdentity
{
    /**
     * The `staff` object the Kadi site's back-office API expects on every write:
     * `id` up to 50 characters, `name` up to 100, `email` optional (a valid address up to 150).
     *
     * @return array{id: string, name: string, email?: string}
     */
    public static function forSite(User $user): array
    {
        $staff = [
            'id' => Str::limit((string) $user->getKey(), 50, ''),
            'name' => Str::limit((string) $user->name, 100, ''),
        ];

        $email = (string) $user->email;

        if ($email !== '' && strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $staff['email'] = $email;
        }

        return $staff;
    }

    /**
     * The Kadi API's `admin_user` (max 100 characters), e.g. "Jane Trader (GMS #17)".
     * The name is shortened, never the GMS id, so the label always identifies the user.
     */
    public static function adminUser(User $user): string
    {
        $suffix = ' (GMS #'.$user->getKey().')';

        return Str::limit((string) $user->name, 100 - strlen($suffix), '').$suffix;
    }
}
