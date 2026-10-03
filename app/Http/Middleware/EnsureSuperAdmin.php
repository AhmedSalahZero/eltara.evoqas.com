<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — EnsureSuperAdmin   (route alias: 'super.admin')
//  Location: app/Http/Middleware/EnsureSuperAdmin.php
//
//  Guards /admin/*: only the platform owner gets in. Anyone else who
//  is signed in is sent to their own home page.
//  The Super Admin works across companies, so no company filter is
//  set (App\Support\Tenant stays "not scoped") — and the admin
//  screens deliberately show only companies, limits and usage, never
//  a company's financial data (Scope §3).
// ══════════════════════════════════════════════════════════════════

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user || ! $user->isSuperAdmin()) {
            return redirect()->route('home');
        }

        if (! $user->is_active) {
            return SignOut::because($request, 'web', 'errors.account_suspended');
        }

        return $next($request);
    }
}
