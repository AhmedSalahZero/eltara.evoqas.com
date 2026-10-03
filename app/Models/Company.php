<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Company (the tenant)
//  Location: app/Models/Company.php
//
//  A transport company subscribed to El Tara, and the owner of all
//  its data. Created only by the Super Admin (Scope §4).
//
//  Limits (Scope §4.2) — only ACTIVE accounts count:
//    officeSeatsUsed()  / hasFreeOfficeSeat()   → users table
//    driverSeatsUsed()  / hasFreeDriverSeat()   → drivers table
//
//  Subscription:
//    isReadOnly()      → the end date has passed: people can still
//                        sign in and look, but cannot record anything
//    isExpiringSoon()  → inside the 30-day warning window (banner +
//                        reminder email)
//    isSuspended()     → nobody can sign in at all
// ══════════════════════════════════════════════════════════════════

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_ar',
        'name_en',
        'status',
        'office_users_limit',
        'driver_accounts_limit',
        'subscription_starts_at',
        'subscription_ends_at',
        'expiry_notified_at',
        'default_language',
        'default_theme',
        'contact_phone',
        'contact_email',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'                 => CompanyStatus::class,
            'office_users_limit'     => 'integer',
            'driver_accounts_limit'  => 'integer',
            'subscription_starts_at' => 'date',
            'subscription_ends_at'   => 'date',
            'expiry_notified_at'     => 'datetime',
        ];
    }

    // ── Status & subscription ──────────────────────────────────────

    public function isSuspended(): bool
    {
        return $this->status === CompanyStatus::Suspended;
    }

    /** The subscription has ended (the whole end day is still included). */
    public function isReadOnly(): bool
    {
        return $this->subscription_ends_at !== null
            && $this->subscription_ends_at->copy()->endOfDay()->isPast();
    }

    /** Whole days left until the end date, or null when there is none. Never negative. */
    public function daysUntilExpiry(): ?int
    {
        if ($this->subscription_ends_at === null) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($this->subscription_ends_at->copy()->startOfDay(), false));
    }

    public function isExpiringSoon(): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null
            && ! $this->isReadOnly()
            && $days <= (int) config('eltara.subscription.notify_days_before', 30);
    }

    // ── Limits ─────────────────────────────────────────────────────

    public function officeSeatsUsed(): int
    {
        return $this->users()->where('is_active', true)->count();
    }

    public function hasFreeOfficeSeat(): bool
    {
        return $this->officeSeatsUsed() < $this->office_users_limit;
    }

    public function driverSeatsUsed(): int
    {
        return $this->drivers()->withoutGlobalScopes()->where('is_active', true)->count();
    }

    public function hasFreeDriverSeat(): bool
    {
        return $this->driverSeatsUsed() < $this->driver_accounts_limit;
    }

    /**
     * Adds office_used and drivers_used (active accounts) to each
     * company in one query — used by the Super Admin lists, so 500
     * companies cost 1 query, not 1,000.
     */
    public function scopeWithUsage(Builder $query): Builder
    {
        return $query->withCount([
            'users as office_used' => fn ($q) => $q->where('is_active', true),
            'drivers as drivers_used' => fn ($q) => $q->withoutGlobalScopes()->where('is_active', true),
        ]);
    }

    // ── Display ────────────────────────────────────────────────────

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    // ── Relations ──────────────────────────────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function admin(): HasOne
    {
        return $this->hasOne(User::class)->where('role', UserRole::CompanyAdmin->value)->oldestOfMany();
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
