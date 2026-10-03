<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — EnsureOfficeAccess   (route alias: 'office')
//  Location: app/Http/Middleware/EnsureOfficeAccess.php
//
//  Guards /office/*, the Company Office portal:
//    1. only company admins and office users (the Super Admin goes
//       to /admin);
//    2. the account and its company must still be allowed in —
//       checked on EVERY request, so suspending a user or a company
//       takes effect at once (User::accessDenialReason);
//    3. sets the company filter (App\Support\Tenant) so every query
//       on this request sees only this company's data;
//    4. notes when the person was last active (at most every 5
//       minutes, to keep it cheap), for "last seen" on the users list.
// ══════════════════════════════════════════════════════════════════

class EnsureOfficeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        if ($reason = $user->accessDenialReason()) {
            return SignOut::because($request, 'web', $reason);
        }

        Tenant::set($user->company_id);

        if (! $user->last_activity_at || $user->last_activity_at->lt(now()->subMinutes(5))) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
