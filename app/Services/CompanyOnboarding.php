<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CompanyOnboarding
//  Location: app/Services/CompanyOnboarding.php
//
//  What happens when the Super Admin clicks "Create company"
//  (Scope §4.1), all or nothing:
//    1. the company is saved with its limits and subscription;
//    2. its admin account is created — with a random password that
//       nobody knows, and not yet activated;
//    3. both are written to the audit log;
//    4. AFTER the database work succeeds, the admin receives the
//       activation email to choose their own password.
//  If the email fails (mail server down), the company still exists
//  and the Super Admin can press "Resend activation".
// ══════════════════════════════════════════════════════════════════

final class CompanyOnboarding
{
    public function __construct(private readonly AccountInvitation $invitation) {}

    /** @param array<string, mixed> $data validated StoreCompanyRequest data */
    public function create(array $data, User $superAdmin): Company
    {
        [$company, $admin] = DB::transaction(function () use ($data, $superAdmin) {
            $company = Company::query()->create([
                'name_ar'                => $data['name_ar'],
                'name_en'                => $data['name_en'],
                'status'                 => $data['status'],
                'office_users_limit'     => $data['office_users_limit'],
                'driver_accounts_limit'  => $data['driver_accounts_limit'],
                'subscription_starts_at' => $data['subscription_starts_at'],
                'subscription_ends_at'   => $data['subscription_ends_at'],
                'default_language'       => $data['default_language'],
                'default_theme'          => $data['default_theme'] ?? 'dark',
                'contact_phone'          => $data['contact_phone'] ?? null,
                'contact_email'          => $data['contact_email'] ?? null,
                'notes'                  => $data['notes'] ?? null,
                'created_by'             => $superAdmin->id,
            ]);

            $admin = User::query()->create([
                'company_id' => $company->id,
                'name'       => $data['admin_name'],
                'email'      => $data['admin_email'],
                'phone'      => $data['admin_phone'] ?? null,
                'password'   => Str::password(32),
                'role'       => UserRole::CompanyAdmin->value,
                'job_title'  => $data['admin_job_title'] ?? null,
                'language'   => $data['default_language'],
                'is_active'  => true,
                'created_by' => $superAdmin->id,
            ]);

            CompanyDefaults::ensure($company->id);

            Audit::record('company.created', $company, ['after' => $company->only([
                'name_ar', 'name_en', 'office_users_limit', 'driver_accounts_limit', 'subscription_ends_at',
            ]) + ['status' => $company->status->value, 'admin_email' => $admin->email]]);

            return [$company, $admin];
        });

        $this->invitation->send($admin);

        return $company;
    }
}
