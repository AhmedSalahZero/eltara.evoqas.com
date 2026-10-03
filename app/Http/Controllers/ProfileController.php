<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use App\Support\PasswordRules;
use Illuminate\Support\Arr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ProfileController ("My profile")
//  Location: app/Http/Controllers/ProfileController.php
//
//  Name, mobile and password of the signed-in person — used by the
//  office, the Super Admin (/profile) and the client portal
//  (/client/profile). Changing the password signs the account out on
//  every OTHER device, so a stolen or shared password stops working
//  everywhere at once.
// ══════════════════════════════════════════════════════════════════

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $account = $this->account($request);

        return Inertia::render('Profile/Index', [
            'profile' => $account->only(['name', 'email', 'phone', 'job_title']),
            'portal'  => $account instanceof ClientUser ? 'client' : 'office',
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $account = $this->account($request);
        // The current password was only needed as proof; it is never saved.
        $data = Arr::only($request->validated(), ['name', 'phone']);
        $oldPhone = $account->phone;

        $account->forceFill($data)->save();

        if ((string) ($account->phone ?? '') !== (string) ($oldPhone ?? '')) {
            Audit::record('profile.phone_changed', $account, ['before' => $oldPhone, 'after' => $account->phone]);
        }

        return back()->with('success', __('common.saved'));
    }

    public function password(Request $request): RedirectResponse
    {
        $guard = $request->routeIs('client.*') ? 'client' : 'web';

        $request->validate([
            'current_password' => ['required', 'current_password:'.$guard],
            'password'         => ['required', 'confirmed', PasswordRules::defaults()],
        ]);

        $account = $this->account($request);
        // Saving a new password also ends this person's OTHER sessions and remembered sign-ins, and keeps
        // this one valid (see App\Support\Concerns\EndsSessionsOnCredentialChange, audit Q23).
        $account->forceFill(['password' => $request->input('password')])->save();

        return back()->with('success', __('auth.password_reset_done'));
    }

    private function account(Request $request): User|ClientUser
    {
        return $request->routeIs('client.*') ? $request->user('client') : $request->user('web');
    }
}
