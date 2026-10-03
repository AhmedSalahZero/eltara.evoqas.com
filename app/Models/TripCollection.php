<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripCollection (cash a client handed to the driver)
//  Location: app/Models/TripCollection.php
//
//  Scope §9 — two-sided confirmation:
//    the driver records it → the client confirms or disputes (portal)
//    the client records it → the driver confirms receipt (app)
//  state():
//    confirmed        both sides confirmed
//    awaiting_client  the driver confirmed, the client not yet
//    awaiting_driver  the client recorded, the driver not yet
//    disputed         one side disputed, management has not resolved
//    cancelled        management resolved the dispute: not received
//
//  counts(): whether the amount is in the driver's collections
//  balance — the driver has confirmed holding it and it is not in
//  an open dispute (Scope §9: disputed amounts do not count until
//  management resolves). Kept in step with the ledger by
//  App\Services\Trips\CollectionService.
// ══════════════════════════════════════════════════════════════════

class TripCollection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'trip_id', 'driver_id', 'customer_id', 'amount', 'received_at', 'recorded_by', 'note', 'receipt_path',
        'driver_confirmed_at', 'client_confirmed_at', 'disputed_at', 'disputed_by', 'dispute_note',
        'resolved_at', 'resolved_by', 'resolution', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'              => 'float',
            'received_at'         => 'datetime',
            'driver_confirmed_at' => 'datetime',
            'client_confirmed_at' => 'datetime',
            'disputed_at'         => 'datetime',
            'resolved_at'         => 'datetime',
        ];
    }

    public function isOpenDispute(): bool
    {
        return $this->disputed_at !== null && $this->resolved_at === null;
    }

    public function state(): string
    {
        return match (true) {
            $this->resolution === 'cancelled'                                  => 'cancelled',
            $this->isOpenDispute()                                              => 'disputed',
            $this->driver_confirmed_at && $this->client_confirmed_at            => 'confirmed',
            $this->driver_confirmed_at !== null                                 => 'awaiting_client',
            default                                                             => 'awaiting_driver',
        };
    }

    public function counts(): bool
    {
        return $this->resolution !== 'cancelled' && $this->driver_confirmed_at !== null && ! $this->isOpenDispute();
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
