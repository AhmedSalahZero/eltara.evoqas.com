<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ClientRequestLine (one kind of truck inside a request)
//  Location: app/Models/ClientRequestLine.php
//  "4 trucks on route 6 October – Alexandria – 5 Ton at 10,200 each".
//  The route carries the weight; the price is the client's agreed
//  price copied when he sent the request. A request has one or more.
// ══════════════════════════════════════════════════════════════════

class ClientRequestLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'client_request_id', 'trip_route_id', 'trucks_count', 'unit_price'];

    protected function casts(): array
    {
        return ['trucks_count' => 'integer', 'unit_price' => 'float'];
    }

    public function subtotal(): float
    {
        return round($this->unit_price * $this->trucks_count, 2);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class, 'client_request_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TripRoute::class, 'trip_route_id');
    }
}
