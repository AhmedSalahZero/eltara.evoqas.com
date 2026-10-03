<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\EndsSessionsOnCredentialChange;
use App\Support\Concerns\ChecksCompanyAccess;
use App\Support\Concerns\HasPreferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver
//  Location: app/Models/Driver.php
//
//  A driver of one transport company. Signs in to the Driver App
//  (PWA, /driver) with mobile number + 4-digit PIN, using the
//  'driver' guard (config/auth.php) — completely separate from the
//  office sign-in.
//
//  The PIN is stored hashed ('pin' => 'hashed'), exactly like a
//  password, and is what Laravel checks at sign-in
//  (getAuthPassword()).
//
//  Company-scoped (BelongsToCompany): office users only ever see
//  their own company's drivers.
//
//  Drivers never see prices or profits (Scope §8.1) — the Driver App
//  endpoints only return the driver's own trips and wallets.
// ══════════════════════════════════════════════════════════════════

class Driver extends Authenticatable
{
    use BelongsToCompany, ChecksCompanyAccess, EndsSessionsOnCredentialChange, HasFactory, HasPreferences, Notifiable;

    public const PAY_BASES = ['fixed', 'fixed_plus_trip', 'per_trip'];

    protected $fillable = [
        'company_id',
        'name',
        'mobile',
        'pin',
        'license_number',
        'license_expires_at',
        'pay_basis',
        'base_salary',
        'notes',
        'joined_at',
        'language',
        'theme',
        'is_active',
        'last_login_at',
        'last_sync_at',
        'created_by',
    ];

    protected $hidden = [
        'pin',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'pin'                => 'hashed',
            'is_active'          => 'boolean',
            'license_expires_at' => 'date',
            'joined_at'          => 'date',
            'base_salary'        => 'float',
            'last_login_at'      => 'datetime',
            'last_sync_at'       => 'datetime',
        ];
    }

    /** Laravel checks the PIN where it would normally check a password. */
    public function getAuthPasswordName(): string
    {
        return 'pin';
    }

    /** The truck this driver usually drives (vehicles.driver_id). */
    public function vehicle(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Vehicle::class);
    }

    /** His trips (Step 3). */
    public function trips(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
