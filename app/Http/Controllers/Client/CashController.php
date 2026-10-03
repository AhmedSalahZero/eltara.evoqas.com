<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClient;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Services\Trips\CollectionService;
use App\Services\Trips\TripRuleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client\CashController ( /client/cash )
//  Location: app/Http/Controllers/Client/CashController.php
//
//  Scope §7 "Cash handed to drivers" and §9 two-sided confirmation:
//    index   → totals (handed, confirmed by both, waiting for the
//              client, disputed) and the full log with confirm /
//              dispute; the trips he can record an amount on
//    confirm → he agrees the driver took this amount
//    dispute → he says it is not right (with a note): management
//              decides, and until then it does not count
//    record  → he says he handed the driver an amount; the driver
//              confirms it in his app. Only for clients allowed to pay
//              drivers cash (Customer → may_pay_driver_cash).
//  All rules live in App\Services\Trips\CollectionService.
// ══════════════════════════════════════════════════════════════════

class CashController extends Controller
{
    use ServesClient;

    public function index(Request $request): Response
    {
        $client = $this->client($request);
        $filter = in_array($request->query('filter'), ['waiting', 'disputed', 'confirmed'], true) ? $request->query('filter') : 'all';

        $all = TripCollection::query()->where('customer_id', $client->customer_id)->with('trip:id,number')->orderByDesc('received_at')->limit(500)->get();

        $sum = fn ($rows) => round((float) $rows->sum('amount'), 2);
        $active = $all->filter(fn (TripCollection $c) => $c->state() !== 'cancelled');
        $byState = fn (string $state) => $active->filter(fn (TripCollection $c) => $c->state() === $state);
        $waiting = $active->filter(fn (TripCollection $c) => in_array($c->state(), ['awaiting_client', 'awaiting_driver'], true));

        $rows = match ($filter) {
            'waiting'   => $waiting,
            'disputed'  => $byState('disputed'),
            'confirmed' => $byState('confirmed'),
            default     => $all,
        };

        $customer = Customer::query()->find($client->customer_id);
        $trips = $customer?->may_pay_driver_cash
            ? $this->myTrips($request)->whereIn('trips.status', ['accepted', 'loading', 'on_road'])->whereNotNull('trips.driver_id')->orderBy('trips.loading_at')->get(['id', 'number'])
            : collect();

        return Inertia::render('Client/Cash/Index', [
            'totals'  => [
                'handed'    => $sum($active),
                'confirmed' => $sum($byState('confirmed')),
                'waiting'   => $sum($waiting),
                'waiting_client' => $sum($byState('awaiting_client')),
                'disputed'  => $sum($byState('disputed')),
            ],
            'rows'    => $rows->take(200)->map(fn (TripCollection $c) => $this->collectionRow($c))->values(),
            'trips'   => $trips->map(fn (Trip $t) => ['id' => $t->id, 'number' => $t->number])->values(),
            'canRecord' => (bool) $customer?->may_pay_driver_cash,
            'filter'  => $filter,
        ]);
    }

    public function confirm(Request $request, int $collection, CollectionService $service): RedirectResponse
    {
        $model = $this->ownCollection($request, $collection);

        return $this->attempt(fn () => $service->confirmByClient($model, $this->actor($request)), __('client.ok.confirmed'));
    }

    public function dispute(Request $request, int $collection, CollectionService $service): RedirectResponse
    {
        $model = $this->ownCollection($request, $collection);
        $data = $request->validate(['note' => ['required', 'string', 'max:250']]);

        return $this->attempt(fn () => $service->dispute($model, 'client', $this->actor($request), $data['note']), __('client.ok.disputed'));
    }

    public function record(Request $request, CollectionService $service): RedirectResponse
    {
        $client = $this->client($request);
        $data = $request->validate([
            'trip_id' => ['required', 'integer'],
            'amount'  => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'note'    => ['nullable', 'string', 'max:250'],
        ]);

        $trip = $this->ownTrip($request, $data['trip_id']);

        return $this->attempt(function () use ($client, $trip, $data, $service, $request) {
            if (! Customer::query()->whereKey($client->customer_id)->value('may_pay_driver_cash')) {
                throw TripRuleException::because('client.cash_not_allowed');
            }
            $service->record($trip, (float) $data['amount'], 'client', $this->actor($request), $data['note'] ?? null);
        }, __('client.ok.cash_recorded'));
    }
}
