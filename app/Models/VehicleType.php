<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — VehicleType (the kind of truck: Heavy Truck, Tanker …)
//  Location: app/Models/VehicleType.php
//
//  The 12 standard types (STANDARD below, from the business owner's
//  Trucks.xlsx) are created for every company by
//  App\Services\CompanyDefaults. A company can add its own types,
//  rename them or hide them. Standard ones cannot be deleted.
//  Used by the "Truck type" selector on the truck form.
// ══════════════════════════════════════════════════════════════════

class VehicleType extends Model
{
    use BelongsToCompany;

    /** code => [Arabic, English] — in the order of the Trucks.xlsx file */
    public const STANDARD = [
        'small_pickup'   => ['ربع نقل', 'Small Pickup'],
        'pickup'         => ['نصف نقل', 'Pickup Truck'],
        'light'          => ['نقل خفيف', 'Light Truck'],
        'medium'         => ['نقل متوسط', 'Medium Truck'],
        'heavy'          => ['نقل ثقيل', 'Heavy Truck'],
        'extra_heavy'    => ['نقل ثقيل جدًا', 'Extra Heavy Truck'],
        'dump'           => ['قلاب', 'Dump Truck'],
        'refrigerated'   => ['ثلاجة', 'Refrigerated Truck'],
        'tanker'         => ['تانك', 'Tanker Truck'],
        'tractor_trailer' => ['تريلا', 'Tractor-Trailer'],
        'tractor_head'   => ['رأس تريلا', 'Tractor Head'],
        'trailer'        => ['مقطورة', 'Trailer'],
    ];

    protected $fillable = ['company_id', 'code', 'name_ar', 'name_en', 'is_system', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }
}
