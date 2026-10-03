<?php

namespace App\Http\Requests\Auth;

use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use App\Support\EgyptPhone;
use App\Support\SessionBinding;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — LoginRequest (office + client portal sign-in)
//  Location: app/Http/Requests/Auth/LoginRequest.php
//
//  One sign-in page for office staff, the Super Admin AND client-
//  portal users (the demo's "email or mobile" box). Drivers do NOT
//  sign in here — they use the Driver App with mobile + PIN.
//
//  What decides whether a sign-in succeeds:
//    1. rate limit: 5 wrong tries per login + IP, then a wait;
//    2. find the account by email, or by mobile number —
//       office users first, then client users;
//    3. password correct;
//    4. account activated (the person set their own password from
//       the activation email);
//    5. account + company still allowed in (accessDenialReason).
//
//  authenticate() returns which door was used: 'web' or 'client'.
// ══════════════════════════════════════════════════════════════════

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login'    => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['login' => __('auth.login_field')];
    }

    /** @return 'web'|'client' */
    public function authenticate(): string
    {
        try {
            $this->ensureIsNotRateLimited();
        } catch (ValidationException $e) {
            $this->auditAttempt('auth.login_locked', null, 'too_many_tries');

            throw $e;
        }

        [$guard, $account] = $this->findAccount();

        if (! $account || ! Hash::check((string) $this->string('password'), $account->password)) {
            RateLimiter::hit($this->throttleKey(), 15 * 60);
            $this->auditAttempt('auth.login_failed', $account, 'wrong_login_or_password');

            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        if (! $account->email_verified_at) {
            $this->auditAttempt('auth.login_failed', $account, 'not_activated');

            throw ValidationException::withMessages(['login' => __('auth.not_activated')]);
        }

        if ($reason = $account->accessDenialReason()) {
            $this->auditAttempt('auth.login_failed', $account, $reason);

            throw ValidationException::withMessages(['login' => __($reason)]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::guard($guard)->login($account, $this->boolean('remember'));
        $account->forceFill(['last_login_at' => now()])->saveQuietly();
        SessionBinding::store($guard, $account);
        $this->auditAttempt('auth.login', $account);

        return $guard;
    }

    /**
     * Writes the sign-in attempt to the audit log (Scope §12). A fault while writing it must never stop
     * a person signing in, so it is swallowed and reported. The password is never stored; the typed
     * login (e-mail or mobile) is, so a failed try can be traced to who it was aimed at.
     */
    private function auditAttempt(string $action, User|ClientUser|null $account, ?string $reason = null): void
    {
        try {
            Audit::record($action, null, array_filter([
                'login'  => $account ? null : mb_substr(trim((string) $this->string('login')), 0, 150),
                'reason' => $reason,
            ]), $account?->company_id, $account);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array{0: 'web'|'client', 1: User|ClientUser|null} */
    private function findAccount(): array
    {
        $login = trim((string) $this->string('login'));

        if (str_contains($login, '@')) {
            $email = Str::lower($login);

            if ($user = User::query()->where('email', $email)->first()) {
                return ['web', $user];
            }

            return ['client', ClientUser::query()->withoutGlobalScopes()->where('email', $email)->first()];
        }

        $mobile = EgyptPhone::normalize($login);

        if (! EgyptPhone::isValid($mobile)) {
            return ['web', null];
        }

        // A mobile shared by two accounts would be ambiguous — refuse
        // rather than guess who is signing in.
        $users = User::query()->where('phone', $mobile)->limit(2)->get();
        $clients = ClientUser::query()->withoutGlobalScopes()->where('phone', $mobile)->limit(2)->get();

        if ($users->count() + $clients->count() !== 1) {
            return ['web', null];
        }

        return $users->isNotEmpty() ? ['web', $users->first()] : ['client', $clients->first()];
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
        ]);
    }

    public function throttleKey(): string
    {
        return 'login:'.Str::transliterate(Str::lower((string) $this->string('login'))).'|'.$this->ip();
    }
}
