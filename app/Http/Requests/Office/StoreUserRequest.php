<?php

namespace App\Http\Requests\Office;

use App\Http\Requests\Concerns\NormalizesContactFields;
use App\Support\EgyptPhone;
use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  El Tara — StoreUserRequest (company admin → "New user")
//  Location: app/Http/Requests/Office/StoreUserRequest.php
//
//  A new office user: name, job title, email (receives the
//  activation link), optional mobile, language, and optionally the
//  permissions to start with — either ticked, or copied from another
//  user (Scope §5 "Copy permissions from another user").
//  The office-users limit is checked in the controller.
// ══════════════════════════════════════════════════════════════════

class StoreUserRequest extends FormRequest
{
    use NormalizesContactFields;

    public function authorize(): bool
    {
        return $this->user('web')?->can('users.create') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeContact(['email'], ['phone']);
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:120'],
            'job_title'         => ['nullable', 'string', 'max:100'],
            'email'             => ['required', 'email', 'max:150', $this->signInEmailIsFree()],
            'phone'             => ['nullable', 'regex:'.EgyptPhone::PATTERN, $this->signInPhoneIsFree()],
            'language'          => ['required', 'in:ar,en'],
            'copy_from_user_id' => ['nullable', 'integer'],
        ];
    }
}
