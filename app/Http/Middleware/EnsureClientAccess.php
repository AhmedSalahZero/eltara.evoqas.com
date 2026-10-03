<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — EnsureClientAccess   (route alias: 'client.portal')
//  Location: app/Http/Middleware/EnsureClientAccess.php
//
//  Guards /client/*, the Client Portal:
//    · signed in on the 'client' guard;
//    · the client user, their customer and the transport company are
//      all still active (ClientUser::accessDenialReason);
//    · sets the company filter (App\Support\Tenant). Each client
//      screen additionally filters by the client's own customer_id —
//      a client never sees another client of the same company.
// ══════════════════════════════════════════════════════════════════

class EnsureClientAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user('client');

        if (! $client) {
            return redirect()->guest(route('login'));
        }

        if ($reason = $client->accessDenialReason()) {
            return SignOut::because($request, 'client', $reason);
        }

        Tenant::set($client->company_id);

        return $next($request);
    }
}
