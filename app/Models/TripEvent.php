<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripEvent (a key moment on the trip's timeline)
//  Location: app/Models/TripEvent.php
//
//  Scope §6.3 "key moments timeline (with location)". One row per
//  moment: created, accepted, custody_issued, loading, departed,
//  delivered, settled, cancelled, price_changed, policy_changed …
//  Who did it (office user or driver), when, and — from the Driver
//  App (Step 4) — where (lat / lng; there is no live GPS, Scope §8.1).
// ══════════════════════════════════════════════════════════════════

class TripEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'trip_id', 'type', 'occurred_at', 'actor_type', 'actor_id', 'actor_name', 'lat', 'lng', 'note', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'lat'         => 'float',
            'lng'         => 'float',
            'meta'        => 'array',
            'created_at'  => 'datetime',
        ];
    }
}
