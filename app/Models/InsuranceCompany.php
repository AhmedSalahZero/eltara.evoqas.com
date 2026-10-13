<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — InsuranceCompany (a name on the truck form's list)
//  Location: app/Models/InsuranceCompany.php
//
//  The truck still stores the name in vehicles.insurance_company.
//  This list is what the selector offers, and what a save is allowed
//  to use. A company never sees another's names (BelongsToCompany).
// ══════════════════════════════════════════════════════════════════

class InsuranceCompany extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name'];
}
