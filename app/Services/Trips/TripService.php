<?php

namespace App\Services\Trips;

use App\Models\CompanySetting;
use App\Models\Driver;
use App\Models\ExpenseCategory;
use App\Models\RateCard;
use App\Models\Trip;
use App\Models\TripCharge;
use App\Models\TripEvent;
use App\Models\TripRoute;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WalletEntry;
use App\Services\CompanyDefaults;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripService (creating a trip and moving it along)
//  Location: app/Services/Trips/TripService.php
//
//  CREATE (Scope §6.3)
//    · The price fills in from the customer's rate card for the route.
//      Typing a different price — or a price for a route with no rate
//      card — needs "Trips → Edit price"; every price change is audited.
//    · km, usual hours and the standard budget are copied from the
//      route, so later changes to the route never rewrite this trip.
//    · Custody suggested = cash road costs × (1 + buffer %), rounded up
//      to 500 (Scope §10). A hired truck gets no custody; its owner's
//      fee is recorded as a cost paid by the company.
//    · The transfer policy and its limit start from Company settings
//      and can be changed for this trip.
//    · A truck in maintenance cannot be booked. A truck (or driver) can
//      have one trip in progress plus one booked "next trip".
//
//  LIFECYCLE — one step at a time, each with its time and who did it
//    planned → accept → accepted → (issue custody) → start loading
//    → loading → depart → on_road → deliver (proof photo required)
//    → delivered → settle (SettlementService) → settled
//    Cancel: only before any money has moved on the trip.
//
//  The office uses this in Step 3; the Driver App uses the same
//  methods in Step 4 (with Actor::driver and a location).
// ══════════════════════════════════════════════════════════════════

final class TripService
{
    public function __construct(
        private readonly WalletLedger $ledger,
        private readonly ExpenseService $expenses,
    ) {}

    // ── Create & edit ──────────────────────────────────────────────

    public function create(array $data, User $user): Trip
    {
        $companyId = $user->company_id;
        $settings = CompanyDefaults::ensure($companyId);

        /** @var TripRoute $route */
        $route = TripRoute::query()->with('budgets.category')->findOrFail($data['trip_route_id']);
        /** @var Vehicle $vehicle */
        $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);
        $hired = $vehicle->isHired();
        $driverId = $data['driver_id'] ?? ($hired ? null : $vehicle->driver_id);

        $this->assertBookable($vehicle, $driverId ? Driver::query()->findOrFail($driverId) : null, null);

        if (! $hired && ! $driverId) {
            throw TripRuleException::because('trips.needs_driver');
        }

        [$price, $source] = $this->price($user, (int) $data['customer_id'], $route->id, $data['freight_price'] ?? null);

        $custody = $hired ? 0.0 : (isset($data['custody_planned']) && $data['custody_planned'] !== ''
            ? round((float) $data['custody_planned'], 2)
            : $route->suggestedCustody($settings->custody_buffer_percent));

        $trip = DB::transaction(function () use ($data, $user, $companyId, $settings, $route, $vehicle, $hired, $driverId, $price, $source, $custody) {
            $seq = ((int) Trip::query()->withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->max('seq')) + 1;

            $trip = Trip::query()->create([
                'company_id'          => $companyId,
                'seq'                 => $seq,
                'number'              => Trip::numberFor($seq),
                'customer_id'         => $data['customer_id'],
                'trip_route_id'       => $route->id,
                'vehicle_id'          => $vehicle->id,
                'driver_id'           => $driverId,
                'client_request_id'   => $data['client_request_id'] ?? null,
                'is_hired'            => $hired,
                'status'              => 'planned',
                'loading_at'          => $data['loading_at'],
                'cargo_type_id'       => $data['cargo_type_id'] ?? null,
                'weight_tons'         => $data['weight_tons'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'km'                  => $route->km_round_trip,
                'planned_hours'       => $route->usual_hours,
                'freight_price'       => $price,
                'price_source'        => $source,
                'client_pays_cash'    => (bool) ($data['client_pays_cash'] ?? false),
                'custody_planned'     => $custody,
                'standard_budget'     => $route->budgets->mapWithKeys(fn ($b) => [$b->expense_category_id => (float) $b->amount])->all(),
                'transfer_policy'     => $data['transfer_policy'] ?? $settings->default_transfer_policy,
                'auto_transfer_limit' => isset($data['auto_transfer_limit']) && $data['auto_transfer_limit'] !== ''
                    ? round((float) $data['auto_transfer_limit'], 2) : $settings->auto_transfer_limit,
                'created_by'          => $user->id,
            ]);

            $this->event($trip, 'created', Actor::user($user));
            Audit::record('trip.created', $trip, ['after' => [
                'number' => $trip->number, 'price' => $price, 'price_source' => $source, 'custody' => $custody, 'vehicle' => $vehicle->plateText(),
            ]]);

            // A hired truck: its owner's fee is the main cost, paid by the office.
            if ($hired && ! empty($data['hire_fee']) && (float) $data['hire_fee'] > 0) {
                $category = ExpenseCategory::query()->where('code', 'hire')->value('id');
                $this->expenses->record($trip, [
                    'expense_category_id' => $category, 'paid_from' => 'company', 'amount' => $data['hire_fee'],
                    'note' => $vehicle->owner_name,
                ], Actor::user($user));
            }

            return $trip;
        });

        return $trip;
    }

    /**
     * Change what can still change. Vehicle and driver only while the trip
     * is planned; the price needs "Edit price"; nothing once it is closed.
     */
    public function update(Trip $trip, array $data, User $user): Trip
    {
        $this->assertOpen($trip);

        return DB::transaction(function () use ($trip, $data, $user) {
            $before = $trip->only(['vehicle_id', 'driver_id', 'loading_at', 'cargo_type_id', 'weight_tons', 'custody_planned', 'client_pays_cash', 'freight_price']);

            if ($trip->status === 'planned' && isset($data['vehicle_id'])) {
                $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);
                $driverId = $data['driver_id'] ?? ($vehicle->isHired() ? null : $vehicle->driver_id);
                $this->assertBookable($vehicle, $driverId ? Driver::query()->findOrFail($driverId) : null, $trip);

                if (! $vehicle->isHired() && ! $driverId) {
                    throw TripRuleException::because('trips.needs_driver');
                }

                $trip->vehicle_id = $vehicle->id;
                $trip->driver_id = $driverId;
                $trip->is_hired = $vehicle->isHired();
            }

            foreach (['loading_at', 'cargo_type_id', 'weight_tons', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $trip->{$field} = $data[$field];
                }
            }
            if (array_key_exists('client_pays_cash', $data)) {
                $trip->client_pays_cash = (bool) $data['client_pays_cash'];
            }
            if (array_key_exists('custody_planned', $data) && $data['custody_planned'] !== null && $data['custody_planned'] !== '') {
                $trip->custody_planned = $trip->is_hired ? 0 : round((float) $data['custody_planned'], 2);
            }
            if ($trip->is_hired) {
                $trip->custody_planned = 0;
            }

            if (array_key_exists('freight_price', $data) && $data['freight_price'] !== null && $data['freight_price'] !== ''
                && round((float) $data['freight_price'], 2) !== round($trip->freight_price, 2)) {
                if (! $user->can('trips.edit_price')) {
                    throw TripRuleException::because('trips.price_needs_permission');
                }
                $old = $trip->freight_price;
                $trip->freight_price = round((float) $data['freight_price'], 2);
                $trip->price_source = 'manual';
                $this->event($trip, 'price_changed', Actor::user($user), null, ['from' => $old, 'to' => $trip->freight_price]);
                Audit::record('trip.price_changed', $trip, ['before' => ['price' => $old], 'after' => ['price' => $trip->freight_price]]);
            }

            $changes = $trip->getDirty();
            $trip->save();

            if ($changes) {
                Audit::record('trip.updated', $trip, ['before' => array_intersect_key($before, $changes), 'after' => array_map(
                    fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i') : $v, $changes)]);
            }

            return $trip;
        });
    }

    public function changePolicy(Trip $trip, string $policy, ?float $limit, User $user): void
    {
        $this->assertOpen($trip);

        if (! in_array($policy, CompanySetting::POLICIES, true)) {
            throw TripRuleException::because('trips.bad_policy');
        }

        DB::transaction(function () use ($trip, $policy, $limit, $user) {
            $before = ['policy' => $trip->transfer_policy, 'limit' => $trip->auto_transfer_limit];
            $trip->forceFill([
                'transfer_policy'     => $policy,
                'auto_transfer_limit' => $limit !== null ? round($limit, 2) : $trip->auto_transfer_limit,
            ])->save();
            $after = ['policy' => $trip->transfer_policy, 'limit' => $trip->auto_transfer_limit];

            $this->event($trip, 'policy_changed', Actor::user($user), null, $after);
            Audit::record('trip.policy_changed', $trip, ['before' => $before, 'after' => $after]);
        });
    }

    public function addCharge(Trip $trip, string $kind, string $label, float $amount, User $user): TripCharge
    {
        $this->assertOpen($trip);

        if (! in_array($kind, TripCharge::KINDS, true) || $amount <= 0) {
            throw TripRuleException::because('trips.amount_positive');
        }

        return DB::transaction(function () use ($trip, $kind, $label, $amount, $user) {
            $charge = TripCharge::query()->create([
                'company_id' => $trip->company_id, 'trip_id' => $trip->id, 'kind' => $kind,
                'label' => mb_substr($label, 0, 120), 'amount' => round($amount, 2), 'created_by' => $user->id,
            ]);
            Audit::record('trip.charge_added', $trip, ['after' => ['kind' => $kind, 'label' => $label, 'amount' => $charge->amount]]);

            return $charge;
        });
    }

    public function removeCharge(TripCharge $charge, User $user): void
    {
        $this->assertOpen($charge->trip);

        DB::transaction(function () use ($charge) {
            Audit::record('trip.charge_removed', $charge->trip, ['before' => ['kind' => $charge->kind, 'label' => $charge->label, 'amount' => $charge->amount]]);
            $charge->delete();
        });
    }

    // ── Lifecycle ──────────────────────────────────────────────────

    /** The driver accepts the trip (the office can record it for him). */
    public function accept(Trip $trip, Actor $actor, ?float $lat = null, ?float $lng = null, ?\DateTimeInterface $at = null): void
    {
        $this->assertStatus($trip, 'planned');

        if (! $trip->is_hired && ! $trip->driver_id) {
            throw TripRuleException::because('trips.needs_driver');
        }

        $busy = Trip::query()->inProgress()->whereKeyNot($trip->id)
            ->where(fn ($q) => $q->where('vehicle_id', $trip->vehicle_id)->when($trip->driver_id, fn ($w) => $w->orWhere('driver_id', $trip->driver_id)))
            ->value('number');
        if ($busy) {
            throw TripRuleException::because('trips.busy_with', ['trip' => $busy]);
        }

        $this->step($trip, 'accepted', 'accepted_at', 'accepted', $actor, $lat, $lng, null, $at);
    }

    /** Company money handed to the driver for road costs. Can be repeated (a top-up when custody runs out). */
    public function issueCustody(Trip $trip, float $amount, Actor $actor, ?string $note = null, ?float $lat = null, ?float $lng = null): void
    {
        if (! in_array($trip->status, Trip::IN_PROGRESS, true)) {
            throw TripRuleException::because('trips.custody_when');
        }
        if ($trip->is_hired) {
            throw TripRuleException::because('trips.hired_no_custody');
        }
        if (! $trip->driver_id) {
            throw TripRuleException::because('trips.needs_driver');
        }
        if ($amount <= 0) {
            throw TripRuleException::because('trips.amount_positive');
        }

        // Custody has a ceiling: the trip's planned custody plus a margin. Only the company admin
        // may go above it, and then it is marked in the audit log.
        $planned = (float) $trip->custody_planned;
        $overCap = false;
        if ($actor->user && $planned > 0) {
            $cap = round($planned * (1 + (float) config('eltara.custody_cap_percent', 50) / 100), 2);
            $issued = (float) WalletEntry::query()->where('trip_id', $trip->id)->where('wallet', 'custody')->where('type', 'custody_issued')->sum('amount');
            $overCap = $issued + $amount > $cap + 0.001;

            if ($overCap && ! $actor->user->isCompanyAdmin()) {
                throw TripRuleException::because('trips.custody_over_cap', ['cap' => number_format($cap), 'issued' => number_format($issued)]);
            }
        }

        DB::transaction(function () use ($trip, $amount, $actor, $note, $lat, $lng, $overCap) {
            $first = $trip->custody_issued_at === null;
            $event = $this->event($trip, 'custody_issued', $actor, $note, ['amount' => round($amount, 2), 'top_up' => ! $first], $lat, $lng);

            $this->ledger->post($trip->company_id, $trip->driver_id, $trip->id, 'custody', 'custody_issued', $amount, $event, $actor, $note);

            if ($first) {
                $trip->forceFill(['custody_issued_at' => now()])->save();
            }

            Audit::record('custody.issued', $trip, ['after' => ['amount' => round($amount, 2), 'top_up' => ! $first, 'above_cap' => $overCap]]);
        });
    }

    public function startLoading(Trip $trip, Actor $actor, ?float $lat = null, ?float $lng = null, ?\DateTimeInterface $at = null): void
    {
        $this->assertStatus($trip, 'accepted');

        if (! $trip->is_hired && $trip->custody_planned > 0 && $trip->custody_issued_at === null) {
            throw TripRuleException::because('trips.custody_first');
        }

        $this->step($trip, 'loading', 'loading_started_at', 'loading', $actor, $lat, $lng, null, $at);
    }

    public function depart(Trip $trip, Actor $actor, ?float $lat = null, ?float $lng = null, ?\DateTimeInterface $at = null): void
    {
        $this->assertStatus($trip, 'loading');
        $this->step($trip, 'on_road', 'departed_at', 'departed', $actor, $lat, $lng, null, $at);
    }

    /** Delivery: a photo of the stamped delivery note is required (Scope §6.5, §8.2). */
    public function deliver(Trip $trip, UploadedFile|string|null $pod, ?string $receiver, Actor $actor, ?float $lat = null, ?float $lng = null, ?\DateTimeInterface $at = null): void
    {
        $this->assertStatus($trip, 'on_road');

        if (! $pod) {
            throw TripRuleException::because('trips.no_pod');
        }

        DB::transaction(function () use ($trip, $pod, $receiver, $actor, $lat, $lng, $at) {
            $path = $pod instanceof UploadedFile ? $pod->store("trips/{$trip->id}/delivery", 'trip_files') : $pod;
            $trip->forceFill(['pod_path' => $path, 'pod_receiver' => $receiver ? mb_substr($receiver, 0, 120) : null])->save();
            $this->step($trip, 'delivered', 'delivered_at', 'delivered', $actor, $lat, $lng, $receiver, $at);
        });

        // The client hears about it in his bell (Scope §11).
        app(Notifier::class)->toClients($trip->customer_id, 'trip.delivered', ['number' => $trip->number], route('client.shipments.show', $trip, false));
        // … and so does the office, who now has a trip to settle (Scope §11 "Delivery confirmed — client and office").
        $trip->loadMissing('driver:id,name');
        app(Notifier::class)->toOffice($trip->company_id, 'trip_settlement.view', 'trip.delivered_office', ['number' => $trip->number, 'driver' => $trip->driver?->name], route('office.trips.show', $trip, false));
    }

    /** Only before any money has moved on the trip. */
    public function cancel(Trip $trip, string $reason, User $user): void
    {
        if (! in_array($trip->status, ['planned', 'accepted'], true)) {
            throw TripRuleException::because('trips.cannot_cancel');
        }
        if (WalletEntry::query()->where('trip_id', $trip->id)->exists()) {
            throw TripRuleException::because('trips.cancel_money_moved');
        }

        DB::transaction(function () use ($trip, $reason, $user) {
            $trip->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 250)])->save();
            $this->event($trip, 'cancelled', Actor::user($user), $reason);
            Audit::record('trip.cancelled', $trip, ['after' => ['reason' => $reason]]);
        });
    }

    // ── Helpers ────────────────────────────────────────────────────

    public function event(Trip $trip, string $type, Actor $actor, ?string $note = null, ?array $meta = null, ?float $lat = null, ?float $lng = null, ?\DateTimeInterface $at = null): TripEvent
    {
        return TripEvent::query()->create([
            'company_id'  => $trip->company_id,
            'trip_id'     => $trip->id,
            'type'        => $type,
            'occurred_at' => $at ?? now(),
            'lat'         => $lat,
            'lng'         => $lng,
            'note'        => $note ? mb_substr($note, 0, 250) : null,
            'meta'        => $meta,
            ...$actor->columns(),
        ]);
    }

    /**
     * Can this vehicle / driver take a NEW booking? Refuses a truck in
     * maintenance, and a truck or driver that already has a booked
     * (planned) trip — one trip in progress + one next trip is the most.
     */
    public function assertBookable(Vehicle $vehicle, ?Driver $driver, ?Trip $except): void
    {
        if ($vehicle->status === 'maintenance') {
            throw TripRuleException::because('trips.vehicle_maintenance', ['plate' => $vehicle->plateText()]);
        }

        $booked = Trip::query()->where('status', 'planned')->where('vehicle_id', $vehicle->id)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))->value('number');
        if ($booked) {
            throw TripRuleException::because('trips.vehicle_booked', ['plate' => $vehicle->plateText(), 'trip' => $booked]);
        }

        if ($driver) {
            if (! $driver->is_active) {
                throw TripRuleException::because('trips.driver_suspended', ['name' => $driver->name]);
            }
            $booked = Trip::query()->where('status', 'planned')->where('driver_id', $driver->id)
                ->when($except, fn ($q) => $q->whereKeyNot($except->id))->value('number');
            if ($booked) {
                throw TripRuleException::because('trips.driver_booked', ['name' => $driver->name, 'trip' => $booked]);
            }
        }
    }

    /**
     * The agreed price, or the typed one (which needs "Edit price").
     *
     * @return array{0: float, 1: string}  [price, 'rate_card' | 'manual']
     */
    private function price(User $user, int $customerId, int $routeId, mixed $typed): array
    {
        $agreed = RateCard::query()->where('customer_id', $customerId)->where('trip_route_id', $routeId)->value('price');
        $typed = $typed === null || $typed === '' ? null : round((float) $typed, 2);

        if ($agreed !== null && ($typed === null || abs($typed - (float) $agreed) < 0.005)) {
            return [round((float) $agreed, 2), 'rate_card'];
        }

        if ($typed === null) {
            throw TripRuleException::because('trips.no_price');
        }
        if (! $user->can('trips.edit_price')) {
            throw TripRuleException::because($agreed === null ? 'trips.no_rate_card' : 'trips.price_needs_permission');
        }

        return [$typed, 'manual'];
    }

    private function step(Trip $trip, string $status, string $timeColumn, string $eventType, Actor $actor, ?float $lat, ?float $lng, ?string $note = null, ?\DateTimeInterface $at = null): void
    {
        DB::transaction(function () use ($trip, $status, $timeColumn, $eventType, $actor, $lat, $lng, $note, $at) {
            $trip->forceFill(['status' => $status, $timeColumn => $at ?? now()])->save();
            $this->event($trip, $eventType, $actor, $note, null, $lat, $lng, $at);
            Audit::record('trip.'.$eventType, $trip);
        });
    }

    private function assertStatus(Trip $trip, string $expected): void
    {
        if ($trip->status !== $expected) {
            throw TripRuleException::because('trips.wrong_step');
        }
    }

    private function assertOpen(Trip $trip): void
    {
        if (! $trip->isOpen()) {
            throw TripRuleException::because('trips.trip_closed');
        }
    }
}
