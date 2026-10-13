<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MaintenanceJob (one piece of work on a truck)
//  Location: app/Models/MaintenanceJob.php
//
//  periodic  scheduled work (oil, tyres, filters, …) with an interval
//  emergency a breakdown
//  open      the truck is held in maintenance until this is done
//  Recorded through App\Services\Maintenance\MaintenanceService.
//  A finished job that shares_trips puts its cost onto the truck's
//  later trips (MaintenanceShare). An own-workshop job's cost is the
//  stock issued to it, not a typed bill.
// ══════════════════════════════════════════════════════════════════

class MaintenanceJob extends Model
{
    use BelongsToCompany;

    public const KINDS = ['periodic', 'emergency'];

    public const CATEGORIES = ['oil', 'tyres', 'filters', 'other'];

    public const STATUSES = ['open', 'done'];

    public const SOURCES = ['external', 'own'];

    protected $fillable = [
        'company_id', 'vehicle_id', 'kind', 'category', 'status', 'source', 'store_id', 'shares_trips',
        'opened_at', 'completed_at', 'odometer_km', 'cost', 'workshop', 'note', 'interval_km', 'interval_days', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'odometer_km' => 'integer',
            'cost' => 'float',
            'interval_km' => 'integer',
            'interval_days' => 'integer',
            'shares_trips' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceJobLine::class);
    }

    public function expenses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripExpense::class);
    }
}
