<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — EnsureDriverAccess   (route alias: 'driver.app')
//  Location: app/Http/Middleware/EnsureDriverAccess.php
//
//  Guards the Driver App's data endpoints (/driver/api/*). These are
//  called by the app on the phone, so answers are JSON:
//    401 → not signed in (the app shows its sign-in screen and KEEPS
//          everything waiting to upload)
//    403 → the driver or the company was suspended
//  Sets the company filter (App\Support\Tenant) for the request.
// ══════════════════════════════════════════════════════════════════

class EnsureDriverAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $driver = $request->user('driver');

        if (! $driver) {
            return response()->json(['message' => __('auth.signed_out')], 401);
        }

        if ($reason = $driver->accessDenialReason()) {
            return SignOut::because($request, 'driver', $reason);
        }

        Tenant::set($driver->company_id);

        return $next($request);
    }
}
