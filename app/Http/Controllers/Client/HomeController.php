<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClient;
use App\Http\Controllers\Controller;
use App\Models\ClientRequest;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\TripRating;
use App\Services\Trips\TripFigures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client\HomeController ( /client )
//  Location: app/Http/Controllers/Client/HomeController.php
//
//  Scope §7 "Home": a banner for cash waiting for his confirmation;
//  KPIs (active shipments, trips this month, transport cost this month
//  at the agreed prices, his average rating); the live shipments
//  board; latest requests; recently delivered trips with their
//  delivery proof and a rating shortcut.
// ══════════════════════════════════════════════════════════════════

class HomeController extends Controller
{
    use ServesClient;

    public function __invoke(Request $request): Response
    {
        $customerId = $this->client($request)->customer_id;
        $month = now()->startOfMonth();

        $monthTrips = $this->myTrips($request)->whereBetween('trips.loading_at', [$month, $month->copy()->endOfMonth()]);
        $cost = TripFigures::withMoney((clone $monthTrips)->select('trips.*'))->get()->sum(fn (Trip $t) => TripFigures::rowRevenue($t));

        $awaiting = TripCollection::query()->where('customer_id', $customerId)
            ->whereNotNull('driver_confirmed_at')->whereNull('client_confirmed_at')->whereNull('disputed_at')->whereNull('resolution')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount),0) as total')->first();

        $active = $this->withRowData($this->myTrips($request)->whereIn('trips.status', ['planned', 'accepted', 'loading', 'on_road']))
            ->orderBy('trips.loading_at')->limit(12)->get();

        $delivered = $this->withRowData($this->myTrips($request)->whereIn('trips.status', ['delivered', 'settled']))
            ->orderByDesc('trips.delivered_at')->limit(5)->get();

        $requests = ClientRequest::query()->where('customer_id', $customerId)->with('lines.route')->latest('id')->limit(5)->get();

        return Inertia::render('Client/Home', [
            'kpis'      => [
                'active'  => (int) $this->myTrips($request)->whereIn('trips.status', ['planned', 'accepted', 'loading', 'on_road'])->count(),
                'month'   => (int) (clone $monthTrips)->count(),
                'cost'    => round((float) $cost, 2),
                'rating'  => ($avg = TripRating::query()->where('customer_id', $customerId)->avg('stars')) !== null ? round((float) $avg, 1) : null,
            ],
            'awaiting'  => ['count' => (int) $awaiting->n, 'total' => round((float) $awaiting->total, 2)],
            'active'    => $active->map(fn (Trip $t) => $this->tripRow($t))->values(),
            'delivered' => $delivered->map(fn (Trip $t) => $this->tripRow($t))->values(),
            'requests'  => $requests->map(fn (ClientRequest $r) => [
                'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'lines' => $this->requestLines($r),
                'loading_at' => $r->loading_at?->toIso8601String(), 'trucks' => $r->trucks_count,
            ])->values(),
        ]);
    }
}
