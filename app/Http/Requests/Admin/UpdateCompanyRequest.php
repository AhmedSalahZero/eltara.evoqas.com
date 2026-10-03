<?php

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — UpdateCompanyRequest (Super Admin → "Edit limits")
//  Location: app/Http/Requests/Admin/UpdateCompanyRequest.php
//
//  Names, status, limits and subscription dates of an existing
//  company. A limit can never go below the number of accounts that
//  are already active (the Super Admin is told how many), so no one
//  is silently locked out by lowering a number.
// ══════════════════════════════════════════════════════════════════

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');
        $officeUsed = $company->officeSeatsUsed();
        $driversUsed = $company->driverSeatsUsed();

        return [
            'name_ar'                => ['required', 'string', 'max:150'],
            'name_en'                => ['required', 'string', 'max:150'],
            'status'                 => ['required', Rule::in(CompanyStatus::values())],
            'office_users_limit'     => ['required', 'integer', 'max:1000', 'min:'.max(1, $officeUsed)],
            'driver_accounts_limit'  => ['required', 'integer', 'max:5000', 'min:'.$driversUsed],
            'subscription_starts_at' => ['required', 'date'],
            'subscription_ends_at'   => ['required', 'date', 'after_or_equal:subscription_starts_at'],
            'default_language'       => ['required', 'in:ar,en'],
            'default_theme'          => ['required', 'in:dark,light'],
            'contact_phone'          => ['nullable', 'string', 'max:20'],
            'contact_email'          => ['nullable', 'email', 'max:150'],
            'notes'                  => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'office_users_limit.min'    => __('errors.limit_below_usage', ['used' => $company->officeSeatsUsed()]),
            'driver_accounts_limit.min' => __('errors.limit_below_usage', ['used' => $company->driverSeatsUsed()]),
        ];
    }
}
