<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\EndsSessionsOnCredentialChange;
use App\Support\Concerns\ChecksCompanyAccess;
use App\Support\Concerns\HasPreferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ClientUser (a client-portal account)
//  Location: app/Models/ClientUser.php
//
//  A staff member of one Customer. Signs in at the same /login page
//  as the office (email + password) but lands in the Client Portal
//  (/client), using the 'client' guard (config/auth.php).
//
//  Sees ONLY their own customer's requests, shipments, cash
//  confirmations, statements and prices — never costs, profit, other
//  clients, custody or driver advances (Scope §7).
//
//  Access is refused when the account, its customer or the transport
//  company is suspended (accessDenialReason()).
// ══════════════════════════════════════════════════════════════════

class ClientUser extends Authenticatable
{
    use BelongsToCompany, ChecksCompanyAccess, EndsSessionsOnCredentialChange, HasFactory, HasPreferences, Notifiable {
        ChecksCompanyAccess::accessDenialReason as companyAccessDenialReason;
    }

    protected $fillable = [
        'company_id',
        'customer_id',
        'name',
        'email',
        'phone',
        'job_title',
        'password',
        'is_account_admin',
        'language',
        'theme',
        'is_active',
        'email_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password'          => 'hashed',
            'is_account_admin'  => 'boolean',
            'is_active'         => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
        ];
    }

    public function accessDenialReason(): ?string
    {
        if ($reason = $this->companyAccessDenialReason()) {
            return $reason;
        }

        $customerActive = Customer::query()->withoutGlobalScopes()->whereKey($this->customer_id)->value('is_active');

        return $customerActive ? null : 'errors.account_suspended';
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token, broker: 'clients'));
    }

    public function sendActivationNotification(#[\SensitiveParameter] string $token): void
    {
        $this->notify(new \App\Notifications\ActivateAccountNotification($token, broker: 'client_invites'));
    }

    public function isActivated(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
