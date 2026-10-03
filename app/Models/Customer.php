<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Customer (a client / shipper)
//  Location: app/Models/Customer.php
//
//  A client of a transport company. Its staff sign in to the Client
//  Portal as ClientUser accounts. Only the basic record exists in
//  Step 1; rate cards, routes and the full customer screen come in
//  Step 2 (master data).
// ══════════════════════════════════════════════════════════════════

class Customer extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'name_ar',
        'name_en',
        'payment_terms_days',
        'may_pay_driver_cash',
        'contact_phone',
        'contact_name',
        'contact_email',
        'address',
        'tax_number',
        'notes',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days'  => 'integer',
            'may_pay_driver_cash' => 'boolean',
            'is_active'           => 'boolean',
        ];
    }

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    public function clientUsers(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }

    public function rateCards(): HasMany
    {
        return $this->hasMany(RateCard::class);
    }
}
