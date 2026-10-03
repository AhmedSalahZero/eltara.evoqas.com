<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClient;
use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripRating;
use App\Services\Trips\TripFigures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client\ShipmentController ( /client/shipments )
//  Location: app/Http/Controllers/Client/ShipmentController.php
//
//  Scope §7 "My shipments" and "Shipment details":
//    index → all his trips, filter all / active / delivered: truck,
//            driver, status, price, rating
//    show  → five client-friendly steps, truck & driver (with a call
//            button), expected arrival, the timeline with locations,
//            the cash handed on this trip (confirm / dispute), the
//            price, the proof-of-delivery photo, his rating and a
//            complaint form
//    pod   → the delivery photo, only through this checked door
//    rate  → 1–5 stars and a comment, once per delivered trip
//  He never sees costs, profit, custody or the driver's advances.
//  (The invoice number arrives with Step 6.)
// ══════════════════════════════════════════════════════════════════

class ShipmentController extends Controller
{
    use ServesClient;

    public function index(Request $request): Response
    {
        $filter = in_array($request->query('filter'), ['active', 'delivered'], true) ? $request->query('filter') : 'all';
        $search = trim((string) $request->query('search'));

        $base = $this->myTrips($request)->when($search !== '', fn ($q) => $q->where('trips.number', 'like', "%{$search}%"));
        $counts = [
            'all'       => (int) (clone $base)->count(),
            'active'    => (int) (clone $base)->whereIn('trips.status', ['planned', 'accepted', 'loading', 'on_road'])->count(),
            'delivered' => (int) (clone $base)->whereIn('trips.status', ['delivered', 'settled'])->count(),
        ];

        $query = match ($filter) {
            'active'    => (clone $base)->whereIn('trips.status', ['planned', 'accepted', 'loading', 'on_road']),
            'delivered' => (clone $base)->whereIn('trips.status', ['delivered', 'settled']),
            default     => $base,
        };

        $trips = $this->withRowData($query)->orderByDesc('trips.loading_at')->paginate(20)->withQueryString();

        return Inertia::render('Client/Shipments/Index', [
            'trips'   => ['data' => $trips->getCollection()->map(fn (Trip $t) => $this->tripRow($t))->values(), 'links' => $trips->linkCollection()],
            'counts'  => $counts,
            'filters' => ['filter' => $filter, 'search' => $search],
        ]);
    }

    public function show(Request $request, Trip $trip): Response
    {
        $trip = $this->ownTrip($request, $trip);
        $trip->load(['route', 'vehicle.vehicleType', 'driver:id,name,mobile', 'cargoType', 'rating', 'collections', 'charges',
            'events' => fn ($q) => $q->whereIn('type', ['accepted', 'loading', 'departed', 'delivered'])->orderBy('occurred_at')]);

        $revenue = round($trip->freight_price + $trip->charges->sum(fn ($c) => $c->kind === 'deduction' ? -$c->amount : $c->amount), 2);

        return Inertia::render('Client/Shipments/Show', [
            'trip' => [
                ...$this->tripRow($trip->setAttribute('charges_total', $revenue - $trip->freight_price)),
                'from'        => app()->getLocale() === 'en' && $trip->route?->origin_en ? $trip->route->origin_en : $trip->route?->origin_ar,
                'to'          => app()->getLocale() === 'en' && $trip->route?->destination_en ? $trip->route->destination_en : $trip->route?->destination_ar,
                'truck_type'  => $trip->vehicle?->vehicleType?->displayName(),
                'driver_phone' => $trip->driver?->mobile,
                'cargo'       => $trip->cargoType?->displayName(),
                'weight_tons' => $trip->weight_tons,
                'pod_receiver' => $trip->pod_receiver,
                'cash_allowed' => in_array($trip->status, ['accepted', 'loading', 'on_road'], true) && $trip->driver_id && (bool) \App\Models\Customer::query()->whereKey($trip->customer_id)->value('may_pay_driver_cash'),
            ],
            'timeline'    => $trip->events->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type, 'at' => $e->occurred_at?->toIso8601String(),
                'lat' => $e->lat, 'lng' => $e->lng,
            ])->values(),
            'collections' => $trip->collections->sortByDesc('received_at')->map(fn ($c) => $this->collectionRow($c->setRelation('trip', $trip)))->values(),
            'rating'      => $trip->rating ? ['stars' => $trip->rating->stars, 'comment' => $trip->rating->comment] : null,
            'canRate'     => in_array($trip->status, ['delivered', 'settled'], true) && ! $trip->rating,
        ]);
    }

    public function pod(Request $request, Trip $trip)
    {
        $trip = $this->ownTrip($request, $trip);
        abort_unless($trip->pod_path && Storage::disk('trip_files')->exists($trip->pod_path), 404);

        return Storage::disk('trip_files')->response($trip->pod_path);
    }

    public function rate(Request $request, Trip $trip): RedirectResponse
    {
        $trip = $this->ownTrip($request, $trip);
        $client = $this->client($request);

        $data = $request->validate([
            'stars'   => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        if (! in_array($trip->status, ['delivered', 'settled'], true)) {
            return back()->with('error', __('client.rate_after_delivery'));
        }
        if (TripRating::query()->where('trip_id', $trip->id)->exists()) {
            return back()->with('error', __('client.already_rated'));
        }

        TripRating::query()->create([
            'company_id' => $trip->company_id, 'trip_id' => $trip->id, 'customer_id' => $trip->customer_id,
            'client_user_id' => $client->id, 'stars' => $data['stars'], 'comment' => $data['comment'] ?? null,
        ]);

        return back()->with('success', __('client.ok.rated'));
    }
}
