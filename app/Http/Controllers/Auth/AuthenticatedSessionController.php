<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Auth\AuthenticatedSessionController
//  Location: app/Http/Controllers/Auth/AuthenticatedSessionController.php
//
//  The sign-in page (/login) for office staff, the Super Admin and
//  client-portal users, and signing out.
//  After sign-in:  Super Admin → /admin · office → /office ·
//                  client → /client  (via the home route).
//  All the checks live in App\Http\Requests\Auth\LoginRequest.
//
//  ?reason=… shows why someone was signed out (e.g. their company was
//  suspended while they were working) — see Middleware\SignOut.
// ══════════════════════════════════════════════════════════════════

class AuthenticatedSessionController extends Controller
{
    private const REASONS = ['errors.account_suspended', 'errors.company_suspended', 'errors.account_orphaned'];

    public function create(Request $request): Response
    {
        $reason = in_array($request->query('reason'), self::REASONS, true) ? __($request->query('reason')) : null;

        return Inertia::render('Auth/Login', [
            'status' => session('status'),
            'reason' => $reason,
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $guard = $request->authenticate();

        $request->session()->regenerate();

        return $guard === 'client'
            ? redirect()->intended(route('client.home', absolute: false))
            : redirect()->intended(route('home', absolute: false));
    }

    /** Signs out of the office and the client portal (not the Driver App). */
    public function destroy(Request $request): HttpResponse
    {
        Auth::guard('web')->logout();
        Auth::guard('client')->logout();

        if (! Auth::guard('driver')->check()) {
            $request->session()->invalidate();
        }

        $request->session()->regenerateToken();

        // A full page load of the sign-in screen, so nothing from the
        // signed-in session stays on screen.
        return Inertia::location(route('login'));
    }
}
