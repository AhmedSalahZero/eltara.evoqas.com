<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ActivateAccountNotification;
use App\Notifications\ResetPasswordNotification;
use App\Support\Concerns\ChecksCompanyAccess;
use App\Support\Concerns\EndsSessionsOnCredentialChange;
use App\Support\Concerns\HasPreferences;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// ══════════════════════════════════════════════════════════════════
//  El Tara — User (office staff + Super Admin)
//  Location: app/Models/User.php
//
//  Signs in with email + password at /login. Role decides the area:
//    super_admin   → /admin  (platform: companies and limits)
//    company_admin → /office (everything in the company)
//    office_user   → /office (only what their permissions allow)
//
//  Helpers:
//    isSuperAdmin() / isCompanyAdmin() / isOfficeUser()
//    permissionKeys()      → keys held (App\Support\Permissions)
//    accessDenialReason()  → why this account may not sign in now
//                            (App\Support\Concerns\ChecksCompanyAccess)
//    preferredTheme()      → own theme, else the company default
//
//  NOT tenant-scoped on purpose: the Super Admin lists users across
//  companies, and office screens always ask through
//  $company->users(), which filters by company.
// ══════════════════════════════════════════════════════════════════

class User extends Authenticatable
{
    use ChecksCompanyAccess, EndsSessionsOnCredentialChange, HasFactory, HasPreferences, Notifiable {
        ChecksCompanyAccess::accessDenialReason as companyAccessDenialReason;
    }

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'job_title',
        'permissions',
        'approval_limit',
        'language',
        'theme',
        'is_active',
        'email_verified_at',
        'last_login_at',
        'last_activity_at',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'last_activity_at'  => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'permissions'       => 'array',
            'approval_limit'    => 'decimal:2',
        ];
    }

    // ── Roles ──────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin->value;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === UserRole::CompanyAdmin->value;
    }

    public function isOfficeUser(): bool
    {
        return $this->role === UserRole::OfficeUser->value;
    }

    public function roleLabel(?string $locale = null): string
    {
        return UserRole::tryFrom($this->role)?->label($locale) ?? $this->role;
    }

    // ── Permissions ────────────────────────────────────────────────

    public function permissionKeys(): array
    {
        return Permissions::for($this);
    }

    /** Has this person finished activation (chosen their own password)? */
    public function isActivated(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** The Super Admin has no company, so only the account itself is checked. */
    public function accessDenialReason(): ?string
    {
        if ($this->isSuperAdmin()) {
            return $this->is_active ? null : 'errors.account_suspended';
        }

        return $this->companyAccessDenialReason();
    }

    // ── Emails ─────────────────────────────────────────────────────

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendActivationNotification(#[\SensitiveParameter] string $token): void
    {
        $this->notify(new ActivateAccountNotification($token));
    }

    // ── Relations ──────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
