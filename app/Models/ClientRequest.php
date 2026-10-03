<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ClientRequest (a trip a client asked for)
//  Location: app/Models/ClientRequest.php
//
//  Scope §6.2 / §7. status (STATUSES):
//    new        waiting for the office (the client may still withdraw it)
//    approved   the office said yes; trucks not chosen yet (may be days before loading)
//    assigned   trucks chosen — one trip per truck (trips.client_request_id)
//    declined   refused, with a reason the client reads
//    cancelled  withdrawn by the client
//  A request has one or more LINES (route with its weight × number of
//  trucks × agreed price): trucks_count is their total.
//  Decided only through App\Services\ClientRequestService.
// ══════════════════════════════════════════════════════════════════

class ClientRequest extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['new', 'approved', 'assigned', 'declined', 'cancelled'];

    protected $fillable = [
        'company_id', 'customer_id', 'seq', 'number', 'requested_by', 'trip_route_id', 'loading_at', 'trucks_count', 'cargo_type_id',
        'notes', 'expected_unit_price', 'status', 'decline_reason', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'loading_at'          => 'datetime',
            'decided_at'          => 'datetime',
            'trucks_count'        => 'integer',
            'expected_unit_price' => 'float',
        ];
    }

    public static function numberFor(int $seq): string
    {
        return 'R-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /** Expected total of the whole order: every line at its agreed price (lines must be loaded). */
    public function expectedTotal(): ?float
    {
        return $this->lines->isEmpty() ? null : round($this->lines->sum(fn (ClientRequestLine $l) => $l->subtotal()), 2);
    }

    /**
     * One entry per truck asked, in line order — what the office fills in
     * when assigning ("4 × 5 Ton + 2 × 1 Ton" → six slots). Lines must be loaded.
     *
     * @return list<ClientRequestLine>
     */
    public function slots(): array
    {
        return $this->lines->flatMap(fn (ClientRequestLine $l) => array_fill(0, $l->trucks_count, $l))->values()->all();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientRequestLine::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TripRoute::class, 'trip_route_id');
    }

    public function cargoType(): BelongsTo
    {
        return $this->belongsTo(CargoType::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'requested_by');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'client_request_id');
    }
}
