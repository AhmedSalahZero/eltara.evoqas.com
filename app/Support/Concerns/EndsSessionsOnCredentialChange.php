<?php

namespace App\Support\Concerns;

use App\Support\SessionBinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  El Tara — EndsSessionsOnCredentialChange (for User, ClientUser, Driver)
//  Location: app/Support/Concerns/EndsSessionsOnCredentialChange.php
//
//  Whenever the password (or the driver's PIN) is saved with a new
//  value, wherever in the app that happens:
//    · the "remember me" token is replaced, so a remembered browser
//      or phone can no longer sign back in with the old credential;
//    · the person's own session (if he is the one changing it) is
//      updated, while his other sessions end (see SessionBinding).
//  Audit Q23.
// ══════════════════════════════════════════════════════════════════

trait EndsSessionsOnCredentialChange
{
    public static function bootEndsSessionsOnCredentialChange(): void
    {
        static::updating(function (Model $account) {
            if ($account->isDirty($account->getAuthPasswordName())) {
                $account->setAttribute('remember_token', Str::random(60));
            }
        });

        static::saved(function (Model $account) {
            if ($account->wasChanged($account->getAuthPasswordName())) {
                SessionBinding::refreshOwnSession($account);
            }
        });
    }
}
