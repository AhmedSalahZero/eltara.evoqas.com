<?php

namespace App\Http\Controllers\Client\Concerns;

use App\Models\ClientUser;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Services\Trips\Actor;
use App\Services\Trips\TripFigures;
use App\Services\Trips\TripRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ServesClient (shared by the Client Portal controllers)
//  Location: app/Http/Controllers/Client/Concerns/ServesClient.php
//
//  Every client screen must show ONLY the signed-in client's own
//  customer (the company filter alone would show other clients of the
//  same company — Scope §7 "Clients never see … other clients").
//    client()        the signed-in client user
//    myTrips()       trips of his customer, never cancelled ones he
//                    has no reason to see
//    ownTrip()       one trip of his customer or 404
//    ownCollection() one cash record of his customer or 404
//    attempt()       runs a rule; a refusal goes back as a red message
//  Client-friendly wording of a trip's steps: STEPS / step().
// ══════════════════════════════════════════════════════════════════

trait ServesClient
{
    /** confirmed → truck ready → loading → on the road → delivered (Scope §7). */
    public const STEPS = ['confirmed', 'ready', 'loading', 'on_road', 'delivered'];

    protected function client(Request $request): ClientUser
    {
        return $request->user('client');
    }

    protected function myTrips(Request $request): Builder
    {
        return Trip::query()->where('trips.customer_id', $this->client($request)->customer_id)->where('trips.status', '!=', 'cancelled');
    }

    protected function ownTrip(Request $request, Trip|int|string $trip): Trip
    {
        $model = $trip instanceof Trip ? $trip : Trip::query()->findOrFail($trip);
        abort_unless($model->customer_id === $this->client($request)->customer_id && $model->status !== 'cancelled', 404);

        return $model;
    }

    protected function ownCollection(Request $request, TripCollection|int|string $collection): TripCollection
    {
        $model = $collection instanceof TripCollection ? $collection : TripCollection::query()->findOrFail($collection);
        abort_unless($model->customer_id === $this->client($request)->customer_id, 404);

        return $model;
    }

    protected function actor(Request $request): Actor
    {
        return Actor::client($this->client($request));
    }

    /** The step a trip is at, in the client's words (0–4). */
    protected function step(Trip $trip): int
    {
        return match ($trip->status) {
            'planned'  => 0,
            'accepted' => 1,
            'loading'  => 2,
            'on_road'  => 3,
            default    => 4,
        };
    }

    /** Usual travel time is about half of the round trip (assumption shown as "expected"). */
    protected function expectedArrival(Trip $trip): ?string
    {
        if ($trip->status !== 'on_road' || ! $trip->departed_at || ! $trip->planned_hours) {
            return null;
        }

        return $trip->departed_at->copy()->addMinutes((int) round($trip->planned_hours * 60 / 2))->toIso8601String();
    }

    /** A trip as one row of the client's lists: no cost, no profit, no custody. */
    protected function tripRow(Trip $t): array
    {
        return [
            'id'         => $t->id,
            'number'     => $t->number,
            'status'     => $t->status,
            'step'       => $this->step($t),
            'route'      => $t->route?->displayName(),
            'loading_at' => $t->loading_at?->toIso8601String(),
            'delivered_at' => $t->delivered_at?->toIso8601String(),
            'vehicle'    => $t->vehicle ? ['number' => $t->vehicle->plate_number, 'letters' => $t->vehicle->plate_letters] : null,
            'driver'     => $t->driver?->name,
            'price'      => TripFigures::rowRevenue($t),
            'stars'      => $t->rating?->stars,
            'has_pod'    => (bool) $t->pod_path,
            'invoice'    => $t->invoice?->number,
            'arrival'    => $this->expectedArrival($t),
        ];
    }

    /** A trips query with what tripRow() needs, loaded in one go. */
    protected function withRowData(Builder $query): Builder
    {
        return TripFigures::withMoney($query->select('trips.*'))
            ->with(['route', 'vehicle:id,plate_number,plate_letters', 'driver:id,name', 'rating:id,trip_id,stars', 'invoice:id,number']);
    }

    protected function attempt(callable $action, ?string $success = null): RedirectResponse
    {
        try {
            $action();
        } catch (TripRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $success ? back()->with('success', $success) : back();
    }

    /** The lines of a request (lines + route loaded): what he asked for, kind by kind. */
    protected function requestLines(\App\Models\ClientRequest $r): array
    {
        return $r->lines->map(fn (\App\Models\ClientRequestLine $l) => [
            'id' => $l->id, 'route' => $l->route?->displayName(), 'trucks' => $l->trucks_count, 'unit_price' => $l->unit_price, 'subtotal' => $l->subtotal(),
        ])->values()->all();
    }

    protected function collectionRow(TripCollection $c): array
    {
        return [
            'id'          => $c->id,
            'trip'        => $c->trip ? ['id' => $c->trip->id, 'number' => $c->trip->number] : null,
            'amount'      => $c->amount,
            'received_at' => $c->received_at?->toIso8601String(),
            'recorded_by' => $c->recorded_by,
            'state'       => $c->state(),
            'note'        => $c->note,
            'dispute_note' => $c->dispute_note,
            'disputed_by' => $c->disputed_by,
        ];
    }
}
