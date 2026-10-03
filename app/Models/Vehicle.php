<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use App\Support\DocumentExpiry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Vehicle (a truck: own fleet or hired)
//  Location: app/Models/Vehicle.php
//  Table: vehicles (see its migration for every field). Scope §6.7.
//  Filtered to the signed-in company automatically (BelongsToCompany).
// ══════════════════════════════════════════════════════════════════

class Vehicle extends Model
{
    use BelongsToCompany, HasFactory;

    public const OWNERSHIPS = ['own', 'hired'];

    public const STATUSES = ['available', 'maintenance'];

    /** The three documents with an expiry date: key => date column. */
    public const DOCUMENTS = [
        'licence'    => 'licence_expires_at',
        'insurance'  => 'insurance_expires_at',
        'inspection' => 'inspection_expires_at',
    ];

    protected $fillable = [
        'company_id', 'plate_number', 'plate_letters', 'vehicle_type_id', 'model', 'year', 'capacity_tons',
        'ownership', 'owner_name', 'owner_phone', 'driver_id', 'odometer_km', 'std_km_per_litre', 'status',
        'licence_number', 'licence_expires_at', 'insurance_company', 'insurance_policy_number', 'insurance_expires_at',
        'inspection_expires_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year'                  => 'integer',
            'capacity_tons'         => 'float',
            'odometer_km'           => 'integer',
            'std_km_per_litre'      => 'float',
            'licence_expires_at'    => 'date',
            'insurance_expires_at'  => 'date',
            'inspection_expires_at' => 'date',
        ];
    }

    public function isHired(): bool
    {
        return $this->ownership === 'hired';
    }

    public function plateText(): string
    {
        return trim($this->plate_number.' '.$this->plate_letters);
    }

    /** The documents with their expiry state, for screens and alerts. */
    public function documents(): array
    {
        $out = [];
        foreach (self::DOCUMENTS as $key => $column) {
            $out[$key] = DocumentExpiry::describe($this->{$column});
        }

        return $out;
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** Its trips (Step 3). "On a trip" = one of them is in progress. */
    public function trips(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
