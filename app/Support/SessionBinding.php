<?php

namespace App\Support;

use App\Models\ClientUser;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SessionBinding (a sign-in session is tied to the password it was made with)
//  Location: app/Support/SessionBinding.php
//
//  Audit Q23. Every signed-in session remembers a fingerprint of the
//  account's password (office / client) or PIN (driver). When the
//  password or PIN is changed by anyone — the person, an admin
//  resetting a PIN, the "forgot password" link — every OTHER session
//  of that account no longer matches and is signed out on its next
//  request (App\Http\Middleware\EndSessionsAfterCredentialChange).
//  The session of the person who made the change is updated so he
//  stays signed in.
// ══════════════════════════════════════════════════════════════════

final class SessionBinding
{
    /** The three kinds of account that can be signed in, by guard. */
    public const GUARDS = ['web' => User::class, 'client' => ClientUser::class, 'driver' => Driver::class];

    public static function key(string $guard): string
    {
        return 'eltara_credential_'.$guard;
    }

    /** The fingerprint kept in the session: who the account is, plus its stored (hashed) password / PIN. */
    public static function fingerprint(Authenticatable $account): string
    {
        return $account->getAuthIdentifier().'|'.$account->getAuthPassword();
    }

    /** Ties the current session to this account's current password / PIN. */
    public static function store(string $guard, Authenticatable $account): void
    {
        if (request()->hasSession()) {
            request()->session()->put(self::key($guard), self::fingerprint($account));
        }
    }

    /**
     * After a password / PIN was saved: if the account being changed is the one signed in on THIS
     * request (a person changing his own password), keep his session valid with the new fingerprint.
     */
    public static function refreshOwnSession(Model $account): void
    {
        if (! request()->hasSession()) {
            return;
        }

        foreach (self::GUARDS as $guard => $class) {
            if ($account instanceof $class && auth()->guard($guard)->id() === $account->getKey()) {
                self::store($guard, $account);
            }
        }
    }
}
