<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClient;
use App\Http\Controllers\Controller;
use App\Models\CargoType;
use App\Models\ClientRequest;
use App\Models\RateCard;
use App\Models\Trip;
use App\Services\ClientRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client\RequestController ( /client/requests )
//  Location: app/Http/Controllers/Client/RequestController.php
//
//  Scope §7 "Request a trip" and "My requests":
//    index   → his requests with their status (in review / approved /
//              declined with the reason). Once approved: the plate,
//              driver and status of each truck.
//    create  → the form: route (only from his own prices), loading
//              date and time, number of trucks, cargo, notes, with the
//              expected total before he sends it
//    store / cancel → ClientRequestService
// ══════════════════════════════════════════════════════════════════

class RequestController extends Controller
{
    use ServesClient;

    public function index(Request $request): Response
    {
        $customerId = $this->client($request)->customer_id;

        $requests = ClientRequest::query()->where('customer_id', $customerId)
            ->with(['lines.route', 'cargoType', 'trips' => fn ($q) => $q->with(['vehicle:id,plate_number,plate_letters', 'driver:id,name'])->orderBy('id')])
            ->latest('id')->paginate(15)->withQueryString();

        return Inertia::render('Client/Requests/Index', [
            'requests' => [
                'data'  => $requests->getCollection()->map(fn (ClientRequest $r) => [
                    'id'             => $r->id,
                    'number'         => $r->number,
                    'status'         => $r->status,
                    'lines'          => $this->requestLines($r),
                    'loading_at'     => $r->loading_at?->toIso8601String(),
                    'trucks'         => $r->trucks_count,
                    'cargo'          => $r->cargoType?->displayName(),
                    'notes'          => $r->notes,
                    'total'          => $r->expectedTotal(),
                    'decline_reason' => $r->decline_reason,
                    'created_at'     => $r->created_at?->toIso8601String(),
                    'trips'          => $r->trips->map(fn (Trip $t) => [
                        'id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'driver' => $t->driver?->name,
                        'vehicle' => $t->vehicle ? ['number' => $t->vehicle->plate_number, 'letters' => $t->vehicle->plate_letters] : null,
                    ])->values(),
                ])->values(),
                'links' => $requests->linkCollection(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Client/Requests/Create', [
            'places' => $this->places($request),
            'cargo'  => CargoType::query()->where('is_active', true)->ordered()->get()->map(fn (CargoType $c) => ['id' => $c->id, 'name' => $c->displayName()])->values(),
            'preset' => (int) $request->query('route') ?: null,
        ]);
    }

    public function store(Request $request, ClientRequestService $service): RedirectResponse
    {
        $client = $this->client($request);

        $data = $request->validate([
            'lines'                   => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.trip_route_id'   => ['required', 'integer', Rule::exists('rate_cards', 'trip_route_id')->where('customer_id', $client->customer_id)],
            'lines.*.trucks_count'    => ['required', 'integer', 'min:1', 'max:50'],
            'loading_at'    => ['required', 'date'],
            'cargo_type_id' => ['nullable', 'integer', Rule::exists('cargo_types', 'id')->where('company_id', $client->company_id)],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->submit($client, $data);
        } catch (\App\Services\Trips\TripRuleException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('client.requests.index')->with('success', __('client.ok.requested'));
    }

    public function cancel(Request $request, int $clientRequest, ClientRequestService $service): RedirectResponse
    {
        $model = ClientRequest::query()->where('customer_id', $this->client($request)->customer_id)->findOrFail($clientRequest);

        return $this->attempt(fn () => $service->cancel($model), __('client.ok.cancelled'));
    }

    /**
     * His agreed prices grouped by place: "6 October ← Alexandria" with one
     * option per weight ("5 Ton", "1 Ton") and the price of each. The form
     * asks for the place once, then lines of "weight × number of trucks".
     */
    private function places(Request $request): array
    {
        $en = app()->getLocale() === 'en';

        return RateCard::query()->where('customer_id', $this->client($request)->customer_id)->with('route')->get()
            ->filter(fn (RateCard $c) => $c->route?->is_active)
            ->groupBy(fn (RateCard $c) => $c->route->origin_ar.'|'.$c->route->destination_ar)
            ->map(function ($cards, $key) use ($en) {
                $first = $cards->first()->route;
                $from = $en && $first->origin_en ? $first->origin_en : $first->origin_ar;
                $to = $en && $first->destination_en ? $first->destination_en : $first->destination_ar;

                return [
                    'key'     => $key,
                    'name'    => $from.($en ? ' → ' : ' ← ').$to,
                    'options' => $cards->sortBy(fn (RateCard $c) => $c->route->weight_tons ?? 0)->map(fn (RateCard $c) => [
                        'route_id' => $c->trip_route_id,
                        'weight'   => $c->route->weight_tons,
                        'label'    => trim(ltrim($c->route->weightLabel($en), ' –')),
                        'price'    => (float) $c->price,
                    ])->values()->all(),
                ];
            })->sortBy('name')->values()->all();
    }
}
