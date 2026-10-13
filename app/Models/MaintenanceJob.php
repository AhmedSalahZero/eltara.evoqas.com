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
//  The cost is not a trip expense and not a month-close G&A line.
// ══════════════════════════════════════════════════════════════════

class MaintenanceJob extends Model
{
    use BelongsToCompany;

    public const KINDS = ['periodic', 'emergency'];

    public const CATEGORIES = ['oil', 'tyres', 'filters', 'other'];

    public const STATUSES = ['open', 'done'];

    protected $fillable = [
        'company_id', 'vehicle_id', 'kind', 'category', 'status', 'opened_at', 'completed_at',
        'odometer_km', 'cost', 'workshop', 'note', 'interval_km', 'interval_days', 'created_by',
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
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
