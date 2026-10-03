<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesContactFields;
use App\Models\ClientUser;
use App\Support\EgyptPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ProfileUpdateRequest ("My profile" — name and mobile)
//  Location: app/Http/Requests/ProfileUpdateRequest.php
//  For office staff, the Super Admin and client users. The email is
//  the sign-in name, so only an admin changes it.
//  The mobile is a sign-in name too (office users can sign in with it),
//  so CHANGING or removing it needs the current password: someone who
//  finds a signed-in screen left open cannot give the account a new
//  login for themselves. (No SMS service, so the password is the proof.)
// ══════════════════════════════════════════════════════════════════

class ProfileUpdateRequest extends FormRequest
{
    use NormalizesContactFields;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeContact([], ['phone']);
    }

    /** True when the mobile in the form is not the one saved on the account (changed, added or removed). */
    public function phoneIsChanging(): bool
    {
        $account = $this->routeIs('client.*') ? $this->user('client') : $this->user('web');

        return (string) ($this->input('phone') ?? '') !== (string) ($account?->phone ?? '');
    }

    public function rules(): array
    {
        $account = $this->routeIs('client.*') ? $this->user('client') : $this->user('web');
        $guard = $this->routeIs('client.*') ? 'client' : 'web';
        $free = $this->signInPhoneIsFree($account instanceof ClientUser ? null : $account?->id);

        return [
            'name'  => ['required', 'string', 'max:120'],
            'current_password' => [Rule::requiredIf(fn () => $this->phoneIsChanging()), 'nullable', 'current_password:'.$guard],
            'phone' => ['nullable', 'regex:'.EgyptPhone::PATTERN, function (string $attribute, mixed $value, \Closure $fail) use ($account, $free) {
                // Keeping one's own current number is always fine.
                if ($value !== $account?->phone) {
                    $free($attribute, $value, $fail);
                }
            }],
        ];
    }
}
