<?php

namespace App\Http\Middleware;

use App\Models\ClientUser;
use App\Models\Driver;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SetLocale
//  Location: app/Http/Middleware/SetLocale.php
//
//  Chooses the language (ar / en) for this request, so every screen,
//  message and email comes out in the right language and direction:
//
//    1. the signed-in account's saved language (office, client or
//       driver — whichever this part of the site uses);
//    2. otherwise what a guest picked on the sign-in page (session);
//    3. otherwise Arabic — El Tara's default (Scope §2).
// ══════════════════════════════════════════════════════════════════

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        /** @var User|ClientUser|Driver|null $account */
        $account = match (true) {
            $request->is('driver', 'driver/*') => $request->user('driver'),
            $request->is('client', 'client/*') => $request->user('client'),
            default                            => $request->user('web') ?? $request->user('client'),
        };

        if ($account) {
            return $account->preferredLanguage();
        }

        $session = $request->hasSession() ? $request->session()->get('locale') : null;

        if (in_array($session, ['ar', 'en'], true)) {
            return $session;
        }

        return in_array(config('app.locale'), ['ar', 'en'], true) ? config('app.locale') : 'ar';
    }
}
