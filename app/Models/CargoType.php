<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CargoType (the goods a trip carries: Cement, Steel …)
//  Location: app/Models/CargoType.php
//
//  Different from the trip's WEIGHT (tons). Kept as a list, not free
//  text, so reports by cargo add up ("Cement" and "cement" are one
//  row). Users add new goods right from the trip form; the company
//  can rename or hide them in Company settings.
// ══════════════════════════════════════════════════════════════════

class CargoType extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name_ar', 'name_en', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name_ar')->orderBy('id');
    }

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }
}
