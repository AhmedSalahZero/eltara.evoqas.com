<?php

namespace App\Http\Requests\Driver;

use App\Models\Driver;
use App\Support\Audit;
use App\Support\EgyptPhone;
use App\Support\SessionBinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DriverLoginRequest (Driver App: mobile + 4-digit PIN)
//  Location: app/Http/Requests/Driver/DriverLoginRequest.php
//
//  A 4-digit PIN has only 10,000 possibilities, so guessing must be
//  made useless: after 5 wrong PINs for one mobile number that number
//  is locked for 15 minutes (config/eltara.php → driver_app), from
//  whatever phone or network the tries come.
//
//  The mobile is accepted however it is typed (Arabic digits, +20,
//  spaces) and turned into one form by App\Support\EgyptPhone.
//
//  A driver stays signed in on their phone ("remember me" is always
//  on) so the app keeps working on the road with no signal. They are
//  signed out only when they choose to, or when the office suspends
//  them.
// ══════════════════════════════════════════════════════════════════

class DriverLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mobile' => EgyptPhone::normalize($this->input('mobile')),
            'pin'    => EgyptPhone::digits((string) $this->input('pin')),
        ]);
    }

    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'regex:'.EgyptPhone::PATTERN],
            'pin'    => ['required', 'digits:4'],
        ];
    }

    public function attributes(): array
    {
        return ['mobile' => __('auth.mobile'), 'pin' => __('auth.pin')];
    }

    public function authenticate(): Driver
    {
        $key = 'driver-pin:'.$this->input('mobile');
        $max = (int) config('eltara.driver_app.pin_max_attempts', 5);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);
            $this->auditAttempt('auth.login_locked', null, 'too_many_tries');

            throw ValidationException::withMessages([
                'pin' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        }

        $driver = Driver::query()->withoutGlobalScopes()->where('mobile', $this->input('mobile'))->first();

        if (! $driver || ! Hash::check((string) $this->input('pin'), $driver->pin)) {
            RateLimiter::hit($key, (int) config('eltara.driver_app.pin_lockout_minutes', 15) * 60);
            $this->auditAttempt('auth.login_failed', $driver, 'wrong_mobile_or_pin');

            throw ValidationException::withMessages(['pin' => __('auth.failed_pin')]);
        }

        if ($reason = $driver->accessDenialReason()) {
            $this->auditAttempt('auth.login_failed', $driver, $reason);

            throw ValidationException::withMessages(['mobile' => __($reason)]);
        }

        RateLimiter::clear($key);

        Auth::guard('driver')->login($driver, remember: true);
        $driver->forceFill(['last_login_at' => now()])->saveQuietly();
        SessionBinding::store('driver', $driver);
        $this->auditAttempt('auth.login', $driver);

        return $driver;
    }

    /** Sign-in attempts go to the audit log; a fault there must never stop a driver signing in. The PIN is never stored. */
    private function auditAttempt(string $action, ?Driver $driver, ?string $reason = null): void
    {
        try {
            Audit::record($action, null, array_filter([
                'mobile' => $driver ? null : (string) $this->input('mobile'),
                'reason' => $reason,
            ]), $driver?->company_id, $driver);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
