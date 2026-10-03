<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — BlockWritesWhenReadOnly   (route alias: 'read-only')
//  Location: app/Http/Middleware/BlockWritesWhenReadOnly.php
//
//  Scope §4.2: when a company's subscription has ended it becomes
//  READ-ONLY. Everyone can still sign in and look at their data, but
//  nothing new can be recorded until the Super Admin renews it.
//
//  Runs after the portal middleware (which sets the company). Lets
//  through: reading (GET/HEAD), signing out, and the person's own
//  language / theme / password. Refuses every other change:
//    · office and client screens → back, with a clear message;
//    · the Driver App → 423 "Locked". The app keeps the entries on
//      the phone and uploads them automatically after renewal —
//      nothing a driver recorded on the road is lost.
// ══════════════════════════════════════════════════════════════════

class BlockWritesWhenReadOnly
{
    /** Route names that stay allowed while read-only. */
    private const ALWAYS_ALLOWED = [
        'logout',
        'preferences.update',
        'profile.password',
        'client.profile.password',
        'driver.api.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $companyId = Tenant::id();
        $company = $companyId > 0 ? Company::query()->find($companyId) : null;

        if (! $company || ! $company->isReadOnly()) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('errors.read_only'), 'reason' => 'read_only'], 423);
        }

        return back()->with('error', __('errors.read_only'));
    }
}
