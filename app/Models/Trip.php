<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Trip (one truck, one route, one customer)
//  Location: app/Models/Trip.php
//
//  Scope §6.3. Each trip has its own revenue and expenses and its own
//  custody and collections wallets. Table: trips (see the Step 3
//  migration for every column).
//
//  Lifecycle (STATUSES, in order):
//    planned → accepted → loading → on_road → delivered → settled
//    (+ cancelled, only before any money has moved)
//  "Custody issued" is a moment inside "accepted" (custody_issued_at).
//  The invoice number (the last step on the scope's list) is linked
//  in Step 6 (trips.invoice_id → invoices).
//
//  The money logic is NOT here — it lives in app/Services/Trips/*,
//  so the office screens (Step 3) and the Driver App (Step 4) use the
//  very same rules. Figures for screens: App\Services\Trips\TripFigures.
// ══════════════════════════════════════════════════════════════════

class Trip extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['planned', 'accepted', 'loading', 'on_road', 'delivered', 'settled', 'cancelled'];

    /** Started and not yet delivered: the truck and driver are busy. */
    public const IN_PROGRESS = ['accepted', 'loading', 'on_road'];

    /** Not finished: money can still move on these trips. */
    public const OPEN = ['planned', 'accepted', 'loading', 'on_road', 'delivered'];

    protected $fillable = [
        'company_id', 'seq', 'number', 'customer_id', 'trip_route_id', 'vehicle_id', 'driver_id', 'client_request_id', 'is_hired',
        'status', 'loading_at', 'cargo_type_id', 'weight_tons', 'notes', 'km', 'planned_hours', 'freight_price', 'price_source', 'client_pays_cash',
        'custody_planned', 'standard_budget', 'transfer_policy', 'auto_transfer_limit',
        'accepted_at', 'custody_issued_at', 'loading_started_at', 'departed_at', 'delivered_at', 'settled_at', 'cancelled_at',
        'pod_path', 'pod_receiver', 'cancel_reason', 'settled_by', 'created_by', 'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'is_hired'            => 'boolean',
            'client_pays_cash'    => 'boolean',
            'loading_at'          => 'datetime',
            'km'                  => 'integer',
            'planned_hours'       => 'float',
            'weight_tons'         => 'float',
            'freight_price'       => 'float',
            'custody_planned'     => 'float',
            'auto_transfer_limit' => 'float',
            'standard_budget'     => 'array',
            'accepted_at'         => 'datetime',
            'custody_issued_at'   => 'datetime',
            'loading_started_at'  => 'datetime',
            'departed_at'         => 'datetime',
            'delivered_at'        => 'datetime',
            'settled_at'          => 'datetime',
            'cancelled_at'        => 'datetime',
        ];
    }

    // ── State ──────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, self::IN_PROGRESS, true);
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }

    /** Has the trip reached (or passed) this status? */
    public function reached(string $status): bool
    {
        if ($this->status === 'cancelled') {
            return false;
        }

        return array_search($this->status, self::STATUSES, true) >= array_search($status, self::STATUSES, true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('trips.status', self::OPEN);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn('trips.status', self::IN_PROGRESS);
    }

    public static function numberFor(int $seq): string
    {
        return 'T-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    // ── Relations ──────────────────────────────────────────────────

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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(TripCharge::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(TripExpense::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(TripCollection::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(WalletTransfer::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TripEvent::class);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(TripSettlement::class);
    }

    public function clientRequest(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class, 'client_request_id');
    }

    public function rating(): HasOne
    {
        return $this->hasOne(TripRating::class);
    }

    /** The invoice number linked to this trip (Step 6), or null. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Its share of each closed month's G&A (Step 6). */
    public function allocations(): HasMany
    {
        return $this->hasMany(TripAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
