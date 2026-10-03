<?php

namespace App\Http\Requests\Office;

use App\Http\Requests\Concerns\NormalizesContactFields;
use App\Support\EgyptPhone;
use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  El Tara — UpdateUserRequest (company admin → edit a user's details)
//  Location: app/Http/Requests/Office/UpdateUserRequest.php
//  Name, job title, email, mobile. Permissions are saved separately
//  (UpdatePermissionsRequest).
// ══════════════════════════════════════════════════════════════════

class UpdateUserRequest extends FormRequest
{
    use NormalizesContactFields;

    public function authorize(): bool
    {
        return $this->user('web')?->can('users.edit') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeContact(['email'], ['phone']);
    }

    public function rules(): array
    {
        $id = (int) $this->route('user')->id;

        return [
            'name'      => ['required', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'email'     => ['required', 'email', 'max:150', $this->signInEmailIsFree($id)],
            'phone'     => ['nullable', 'regex:'.EgyptPhone::PATTERN, $this->signInPhoneIsFree($id)],
        ];
    }
}
