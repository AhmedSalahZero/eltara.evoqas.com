<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SignOut (helper used by the portal middleware)
//  Location: app/Http/Middleware/SignOut.php
//
//  Signs one account out of one guard and sends the person to the
//  sign-in page with the reason shown (e.g. "Your company account is
//  suspended"). Used when access is withdrawn while someone is
//  already signed in — suspension works immediately, not at the
//  next sign-in.
//  JSON callers (the Driver App) get a 403 with the reason instead.
// ══════════════════════════════════════════════════════════════════

final class SignOut
{
    public static function because(Request $request, string $guard, string $reasonKey): Response
    {
        Auth::guard($guard)->logout();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __($reasonKey), 'reason' => $reasonKey], 403);
        }

        $request->session()->regenerateToken();

        return Inertia::location(route('login', ['reason' => $reasonKey]));
    }
}
