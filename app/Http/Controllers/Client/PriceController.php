<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClient;
use App\Http\Controllers\Controller;
use App\Models\RateCard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client\PriceController ( /client/prices )
//  Location: app/Http/Controllers/Client/PriceController.php
//  Scope §7 "My agreed prices": his rate card per route, each with a
//  "request on this route" shortcut. Only his own prices.
// ══════════════════════════════════════════════════════════════════

class PriceController extends Controller
{
    use ServesClient;

    public function index(Request $request): Response
    {
        $cards = RateCard::query()->where('customer_id', $this->client($request)->customer_id)->with('route')->get()
            ->filter(fn (RateCard $c) => $c->route)
            ->map(fn (RateCard $c) => [
                'route_id' => $c->trip_route_id, 'name' => $c->route->displayName(), 'km' => $c->route->km_round_trip,
                'price' => (float) $c->price, 'active' => (bool) $c->route->is_active,
            ])->sortBy('name')->values();

        return Inertia::render('Client/Prices', ['prices' => $cards]);
    }
}
