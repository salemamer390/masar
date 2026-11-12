<?php

namespace App\Support;

use App\Models\User;

/**
 * The single definition of who may use the private owner dashboard:
 * a super admin account whose email is allowlisted in
 * config('owner.emails'). Used by both the owner middleware and the
 * dedicated owner login so the two never drift apart.
 */
class OwnerGate
{
    public static function allows(?User $user): bool
    {
        if (!$user) return false;
        $emails = array_map('strtolower', array_filter((array) config('owner.emails', [])));
        return $user->isSuperAdmin()
            && in_array(strtolower((string) $user->email), $emails, true);
    }
}
