<?php

namespace App\Http\Middleware;

use App\Support\SessionBinding;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — EndsSessionsAfterCredentialChange
//  Location: app/Http/Middleware/EndSessionsAfterCredentialChange.php
//
//  Runs on every web request. If the password / PIN of the account
//  signed in on this session is no longer the one the session was
//  made with, the session is signed out (audit Q23). Covers all three
//  doors: office, client portal and the Driver App.
//  Laravel's own AuthenticateSession only watches the default (office)
//  guard, so it would leave clients and drivers unprotected.
// ══════════════════════════════════════════════════════════════════

class EndSessionsAfterCredentialChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            foreach (array_keys(SessionBinding::GUARDS) as $guard) {
                $auth = Auth::guard($guard);

                if (! $auth->check()) {
                    continue;
                }

                $key = SessionBinding::key($guard);
                $now = SessionBinding::fingerprint($auth->user());

                $kept = (string) $request->session()->get($key, '');

                // Nothing kept yet, or the session now belongs to a DIFFERENT account (someone else signed in
                // on this browser): remember the new account. Only the SAME account whose password / PIN
                // changed is signed out.
                if ($kept === '' || strstr($kept, '|', true) !== strstr($now, '|', true)) {
                    $request->session()->put($key, $now);
                } elseif (! hash_equals((string) $request->session()->get($key), $now)) {
                    $auth->logout();
                    $request->session()->forget($key);
                }
            }
        }

        return $next($request);
    }
}
