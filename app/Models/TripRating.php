<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripRating (a client's 1–5 stars for one delivered trip)
//  Location: app/Models/TripRating.php
//  Once per trip. Given from the client portal (Scope §7); shown on
//  the trip page and in the office "Complaints & ratings" tab.
// ══════════════════════════════════════════════════════════════════

class TripRating extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'trip_id', 'customer_id', 'client_user_id', 'stars', 'comment'];

    protected function casts(): array
    {
        return ['stars' => 'integer'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'client_user_id');
    }
}
