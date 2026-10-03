<?php

namespace App\Http\Requests\Concerns;

use App\Models\ClientUser;
use App\Models\User;
use App\Support\EgyptPhone;
use Closure;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  El Tara — NormalizesContactFields (shared by the account forms)
//  Location: app/Http/Requests/Concerns/NormalizesContactFields.php
//
//  · Emails are stored lower-case and trimmed, mobiles in one form
//    (01XXXXXXXXX), however they were typed.
//  · signInEmailIsFree() / signInPhoneIsFree(): one email or mobile
//    can open only ONE account across office users AND client users,
//    because both sign in on the same page — two accounts sharing
//    one email would make "who is this?" impossible to answer.
// ══════════════════════════════════════════════════════════════════

trait NormalizesContactFields
{
    protected function normalizeContact(array $emailFields, array $phoneFields): void
    {
        $clean = [];

        foreach ($emailFields as $field) {
            if ($this->filled($field)) {
                $clean[$field] = Str::lower(trim((string) $this->input($field)));
            }
        }

        foreach ($phoneFields as $field) {
            if ($this->filled($field)) {
                $clean[$field] = EgyptPhone::normalize($this->input($field));
            }
        }

        $this->merge($clean);
    }

    protected function signInEmailIsFree(?int $exceptUserId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($exceptUserId) {
            $taken = User::query()->where('email', $value)->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))->exists()
                || ClientUser::query()->withoutGlobalScopes()->where('email', $value)->exists();

            if ($taken) {
                $fail(__('errors.email_taken'));
            }
        };
    }

    protected function signInPhoneIsFree(?int $exceptUserId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($exceptUserId) {
            $taken = User::query()->where('phone', $value)->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))->exists()
                || ClientUser::query()->withoutGlobalScopes()->where('phone', $value)->exists();

            if ($taken) {
                $fail(__('errors.phone_taken'));
            }
        };
    }
}
