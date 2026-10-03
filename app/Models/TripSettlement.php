<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripSettlement (the closing record of a trip's money)
//  Location: app/Models/TripSettlement.php
//
//  Scope §6.5. Written once, when the trip is settled, by
//  App\Services\Trips\SettlementService. It keeps the balances at
//  that moment:
//    custody_balance      returned by the driver (+) or refunded to him (−)
//    collections_balance  handed in by the driver
//    pocket_balance       his own money refunded to him
//    net_amount           custody + collections − pocket:
//                         + the driver hands this over now,
//                         − the company pays him this
//    unconfirmed_amount   collections the client had not yet confirmed
//                         (a warning at the time, kept for the record)
// ══════════════════════════════════════════════════════════════════

class TripSettlement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'trip_id', 'driver_id', 'custody_balance', 'collections_balance', 'pocket_balance', 'net_amount',
        'unconfirmed_amount', 'cash_received_by', 'note', 'settled_by', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'custody_balance'     => 'float',
            'collections_balance' => 'float',
            'pocket_balance'      => 'float',
            'net_amount'          => 'float',
            'unconfirmed_amount'  => 'float',
            'settled_at'          => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
