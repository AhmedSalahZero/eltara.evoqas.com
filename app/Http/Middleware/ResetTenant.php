<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ResetTenant
//  Location: app/Http/Middleware/ResetTenant.php
//
//  Runs first on every request and forgets which company the
//  previous request worked for, and the permission answers it remembered. On a normal server each request is
//  fresh anyway; this makes it certain when one process serves many
//  requests in a row (automated tests, and fast PHP servers such as
//  Laravel Octane that we may use later for speed).
// ══════════════════════════════════════════════════════════════════

class ResetTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        Tenant::clear();
        Permissions::flush(); // remembered permission answers must never outlive their request

        return $next($request);
    }
}
