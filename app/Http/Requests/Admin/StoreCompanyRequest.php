<?php

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
use App\Http\Requests\Concerns\NormalizesContactFields;
use App\Support\EgyptPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — StoreCompanyRequest (Super Admin → "New company")
//  Location: app/Http/Requests/Admin/StoreCompanyRequest.php
//
//  The "New company" form (Scope §4.1): company names (ar + en), its
//  admin (name, mobile, email — who receives the activation link),
//  the two limits, subscription dates, status and default language.
// ══════════════════════════════════════════════════════════════════

class StoreCompanyRequest extends FormRequest
{
    use NormalizesContactFields;

    public function authorize(): bool
    {
        return $this->user('web')?->isSuperAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeContact(['admin_email', 'contact_email'], ['admin_phone', 'contact_phone']);
    }

    public function rules(): array
    {
        return [
            'name_ar'                => ['required', 'string', 'max:150'],
            'name_en'                => ['required', 'string', 'max:150'],
            'status'                 => ['required', Rule::in(CompanyStatus::values())],
            'office_users_limit'     => ['required', 'integer', 'min:1', 'max:1000'],
            'driver_accounts_limit'  => ['required', 'integer', 'min:0', 'max:5000'],
            'subscription_starts_at' => ['required', 'date'],
            'subscription_ends_at'   => ['required', 'date', 'after_or_equal:subscription_starts_at'],
            'default_language'       => ['required', 'in:ar,en'],
            'default_theme'          => ['nullable', 'in:dark,light'],
            'contact_phone'          => ['nullable', 'string', 'max:20'],
            'contact_email'          => ['nullable', 'email', 'max:150'],
            'notes'                  => ['nullable', 'string', 'max:2000'],

            'admin_name'      => ['required', 'string', 'max:120'],
            'admin_email'     => ['required', 'email', 'max:150', $this->signInEmailIsFree()],
            'admin_phone'     => ['nullable', 'regex:'.EgyptPhone::PATTERN, $this->signInPhoneIsFree()],
            'admin_job_title' => ['nullable', 'string', 'max:100'],
        ];
    }
}
