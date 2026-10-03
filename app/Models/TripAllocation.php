<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripAllocation (a trip's share of one closed month)
//  Location: app/Models/TripAllocation.php
//
//  Scope §6.13 steps 3 and 5. When a month is closed, every own-fleet
//  trip that ran in it gets one row: the km that belong to that month,
//  that month's rate, and so its G&A share (km × rate).
//  A trip that spans two months has two rows (split by hours, or
//  wholly in one month, per the company's rule). Its true profit is
//  final once every month it touches is closed.
//  is_estimate = the trip was still on the road when the month closed.
// ══════════════════════════════════════════════════════════════════

class TripAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'trip_id', 'month', 'km', 'hours', 'rate', 'ga_share', 'is_estimate'];

    protected function casts(): array
    {
        return ['km' => 'float', 'hours' => 'float', 'rate' => 'float', 'ga_share' => 'float', 'is_estimate' => 'boolean'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
