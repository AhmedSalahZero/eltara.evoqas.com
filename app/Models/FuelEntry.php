<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — FuelEntry (one refuel)
//  Location: app/Models/FuelEntry.php
//
//  Scope §6.10. Each refuel records litres, price, odometer and
//  station, and whether it was paid by the company fuel card or by
//  cash from the driver's custody (PAID_BY).
//  Recorded and changed through App\Services\Fuel\FuelService, which
//  also keeps the trip's cost right: a refuel linked to a trip is the
//  SAME money as the trip's fuel expense, never a second one.
//  Analysis (km/L against the truck's standard): FuelAnalysis.
// ══════════════════════════════════════════════════════════════════

class FuelEntry extends Model
{
    use BelongsToCompany;

    public const PAID_BY = ['card', 'custody'];

    protected $fillable = [
        'company_id', 'vehicle_id', 'driver_id', 'trip_id', 'trip_expense_id', 'filled_at', 'litres', 'price_per_litre', 'amount',
        'litres_estimated', 'odometer_km', 'station', 'paid_by', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filled_at'        => 'datetime',
            'litres'           => 'float',
            'price_per_litre'  => 'float',
            'amount'           => 'float',
            'litres_estimated' => 'boolean',
            'odometer_km'      => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(TripExpense::class, 'trip_expense_id');
    }
}
