<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\PasswordRules;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Auth\NewPasswordController
//  Location: app/Http/Controllers/Auth/NewPasswordController.php
//
//  Two screens that both end with the person choosing a password:
//
//    /reset-password/{token}  → "Forgot your password?" link
//                               (office: 'users' broker,
//                                client: 'clients' broker — ?for=client)
//    /activate/{token}        → the activation link emailed when an
//                               office account is created
//                               ('invites' broker, valid 7 days).
//                               Choosing the password also marks the
//                               email as confirmed.
//
//  After saving, the person signs in normally with the new password.
// ══════════════════════════════════════════════════════════════════

class NewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email'  => $request->query('email'),
            'token'  => $request->route('token'),
            'mode'   => $request->routeIs('activation.show') ? 'activate' : 'reset',
            'for'    => $this->audience($request->query('for'), (string) $request->query('email')),
        ]);
    }

    /**
     * Office or client? The link says so (&for=client), but a copied or
     * mangled link can lose that part (e.g. copied from the e-mail log
     * file). E-mail addresses are unique across both kinds of account, so
     * when the link does not say, the address itself tells us.
     */
    private function audience(?string $for, string $email): string
    {
        if ($for === 'client') {
            return 'client';
        }

        $email = Str::lower(trim($email));

        if ($email !== '' && ! User::query()->withoutGlobalScopes()->where('email', $email)->exists()
            && ClientUser::query()->withoutGlobalScopes()->where('email', $email)->exists()) {
            return 'client';
        }

        return 'office';
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'mode'     => ['required', 'in:reset,activate'],
            'for'      => ['nullable', 'in:office,client'],
            'password' => ['required', 'confirmed', PasswordRules::defaults()],
        ]);

        $for = $this->audience($request->input('for'), (string) $request->input('email'));

        $broker = match (true) {
            $request->input('mode') === 'activate' && $for === 'client' => 'client_invites',
            $request->input('mode') === 'activate' => 'invites',
            $for === 'client'                      => 'clients',
            default                                => 'users',
        };

        $status = Password::broker($broker)->reset(
            [...$request->only('password', 'password_confirmation', 'token'), 'email' => Str::lower($request->input('email'))],
            function ($account) use ($request) {
                $account->forceFill([
                    'password'          => $request->input('password'),
                    'remember_token'    => Str::random(60),
                    'email_verified_at' => $account->email_verified_at ?? now(),
                ])->save();

                event(new PasswordReset($account));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return redirect()->route('login')->with('status', __(
            $request->input('mode') === 'activate' ? 'auth.activation_done' : 'auth.password_reset_done'
        ));
    }
}
