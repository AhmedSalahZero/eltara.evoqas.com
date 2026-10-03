<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — RateCard (the agreed price: one customer × one route)
//  Location: app/Models/RateCard.php
//  Price per round trip (Scope §6.9). One line per customer and
//  route; every price change is written to the audit log.
// ══════════════════════════════════════════════════════════════════

class RateCard extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'customer_id', 'trip_route_id', 'price', 'notes', 'updated_by'];

    protected function casts(): array
    {
        return ['price' => 'float'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TripRoute::class, 'trip_route_id');
    }
}
