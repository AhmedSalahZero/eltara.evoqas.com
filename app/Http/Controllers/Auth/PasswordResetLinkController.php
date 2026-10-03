<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Auth\PasswordResetLinkController
//  Location: app/Http/Controllers/Auth/PasswordResetLinkController.php
//
//  "Forgot your password?" for office staff and client users.
//  The email is looked up in the office accounts first, then in the
//  client-portal accounts, and the reset link is sent by the right
//  broker (config/auth.php → passwords).
//
//  The answer on screen is ALWAYS the same ("if this email has an
//  account, a link is on its way"), so the page cannot be used to
//  find out which emails have El Tara accounts.
//  Drivers reset their PIN through their company office.
// ══════════════════════════════════════════════════════════════════

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => session('status')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:150']]);

        $email = Str::lower($request->string('email'));

        $broker = ClientUser::query()->withoutGlobalScopes()->where('email', $email)->exists() ? 'clients' : 'users';

        Password::broker($broker)->sendResetLink(['email' => $email]);

        return back()->with('status', __('auth.reset_link_sent'));
    }
}
