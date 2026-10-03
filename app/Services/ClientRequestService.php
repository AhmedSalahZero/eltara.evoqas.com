<?php

namespace App\Services;

use App\Models\ClientRequest;
use App\Models\ClientRequestLine;
use App\Models\ClientUser;
use App\Models\Customer;
use App\Models\RateCard;
use App\Models\Trip;
use App\Models\TripRoute;
use App\Models\User;
use App\Services\Trips\TripRuleException;
use App\Services\Trips\TripService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ClientRequestService (the life of a client's request)
//  Location: app/Services/ClientRequestService.php
//
//  Scope §6.2 / §7:
//    submit   a client asks for trucks at a loading time: one or more
//             LINES, each a route (it carries the weight: "Alexandria –
//             5 Ton") and a number of trucks. Only routes he has an
//             agreed price for can be asked; the price is copied onto
//             the line (he sees it, and the trips keep it).
//    cancel   the client withdraws it while it is still new or approved.
//    approve  the office says yes now; the trucks can be chosen later
//             (a trip asked for in 10 days). Status: approved.
//    assign   the office picks a truck (and driver) for each truck
//             asked: one planned trip per truck, through
//             TripService::create — so all the trip rules (booking
//             clash, suspended driver, …) apply. All or nothing.
//    decline  the office refuses, with a reason the client reads.
//  Both sides are told through Notifier.
// ══════════════════════════════════════════════════════════════════

final class ClientRequestService
{
    public function __construct(private readonly TripService $trips, private readonly Notifier $notifier) {}

    /**
     * @param  array{lines: list<array{trip_route_id:int, trucks_count:int}>, loading_at:mixed, cargo_type_id?:mixed, notes?:?string}  $data
     */
    public function submit(ClientUser $client, array $data): ClientRequest
    {
        // The same route typed twice (two lines of "5 Ton") becomes one line.
        $wanted = [];
        foreach ($data['lines'] ?? [] as $line) {
            $id = (int) $line['trip_route_id'];
            $wanted[$id] = ($wanted[$id] ?? 0) + max(1, (int) ($line['trucks_count'] ?? 1));
        }
        if (! $wanted) {
            throw TripRuleException::because('client.no_lines');
        }
        if (array_sum($wanted) > 50) {
            throw TripRuleException::because('client.too_many_trucks');
        }

        $routes = TripRoute::query()->where('is_active', true)->whereIn('id', array_keys($wanted))->get()->keyBy('id');
        $prices = RateCard::query()->where('customer_id', $client->customer_id)->whereIn('trip_route_id', array_keys($wanted))->pluck('price', 'trip_route_id');

        foreach (array_keys($wanted) as $id) {
            if (! $routes->has($id) || ! $prices->has($id)) {
                throw TripRuleException::because('client.no_price');
            }
        }

        $loadingAt = \Carbon\Carbon::parse($data['loading_at']);
        if ($loadingAt->isPast()) {
            throw TripRuleException::because('client.loading_in_past');
        }

        $request = DB::transaction(function () use ($client, $data, $wanted, $prices, $loadingAt) {
            $seq = ((int) ClientRequest::query()->withoutGlobalScopes()->where('company_id', $client->company_id)->lockForUpdate()->max('seq')) + 1;

            $request = ClientRequest::query()->create([
                'company_id'          => $client->company_id,
                'customer_id'         => $client->customer_id,
                'seq'                 => $seq,
                'number'              => ClientRequest::numberFor($seq),
                'requested_by'        => $client->id,
                'trip_route_id'       => array_key_first($wanted),  // the first line; the lines hold the truth
                'loading_at'          => $loadingAt,
                'trucks_count'        => array_sum($wanted),
                'cargo_type_id'       => $data['cargo_type_id'] ?? null,
                'notes'               => ($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 1000) : null,
                'expected_unit_price' => null,
                'status'              => 'new',
            ]);

            foreach ($wanted as $routeId => $count) {
                ClientRequestLine::query()->create([
                    'company_id' => $client->company_id, 'client_request_id' => $request->id, 'trip_route_id' => $routeId,
                    'trucks_count' => $count, 'unit_price' => round((float) $prices[$routeId], 2),
                ]);
            }

            Audit::record('client_request.submitted', $request, ['after' => ['number' => $request->number, 'trucks' => $request->trucks_count, 'lines' => count($wanted)]]);

            return $request;
        });

        $this->notifier->toOffice($client->company_id, 'client_requests.view', 'client_request.new', [
            'number' => $request->number, 'customer' => $client->customer->displayName(),
        ], route('office.client-requests.index', [], false));

        return $request;
    }

    /** The office says yes; trucks are chosen later with assign(). */
    public function approve(ClientRequest $request, User $user): void
    {
        if ($request->status !== 'new') {
            throw TripRuleException::because('client.request_decided');
        }

        $request->forceFill(['status' => 'approved', 'decided_by' => $user->id, 'decided_at' => now(), 'decline_reason' => null])->save();
        Audit::record('client_request.accepted', $request);

        $this->notifier->toClients($request->customer_id, 'client_request.accepted', [
            'number' => $request->number,
        ], route('client.requests.index', [], false));
    }

    public function cancel(ClientRequest $request): void
    {
        if (! in_array($request->status, ['new', 'approved'], true)) {
            throw TripRuleException::because('client.request_decided');
        }

        $request->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();
        Audit::record('client_request.cancelled', $request);
    }

    /**
     * @param  list<array{vehicle_id:int, driver_id:?int}>  $assignments  one per truck asked, in the order of the request's lines
     * @return list<Trip>
     */
    public function assign(ClientRequest $request, array $assignments, User $user): array
    {
        if (! in_array($request->status, ['new', 'approved'], true)) {
            throw TripRuleException::because('client.request_decided');
        }

        $slots = $request->loadMissing('lines.route')->slots();
        $assignments = array_values($assignments);

        if (count($assignments) !== count($slots)) {
            throw TripRuleException::because('client.assign_count', ['count' => count($slots)]);
        }
        $vehicleIds = array_column($assignments, 'vehicle_id');
        if (count(array_unique($vehicleIds)) !== count($vehicleIds)) {
            throw TripRuleException::because('client.assign_same_truck');
        }

        $trips = DB::transaction(function () use ($request, $assignments, $slots, $user) {
            $made = [];
            foreach ($slots as $i => $line) {
                $made[] = $this->trips->create([
                    'customer_id'       => $request->customer_id,
                    'trip_route_id'     => $line->trip_route_id,
                    'vehicle_id'        => $assignments[$i]['vehicle_id'],
                    'driver_id'         => $assignments[$i]['driver_id'] ?? null,
                    'loading_at'        => $request->loading_at,
                    'cargo_type_id'     => $request->cargo_type_id,
                    'weight_tons'       => $line->route?->weight_tons,
                    'notes'             => $request->notes,
                    'freight_price'     => $line->unit_price,
                    'client_request_id' => $request->id,
                ], $user);
            }

            $request->forceFill(['status' => 'assigned', 'decided_by' => $user->id, 'decided_at' => $request->decided_at ?? now(), 'decline_reason' => null])->save();
            Audit::record('client_request.assigned', $request, ['after' => ['trips' => array_map(fn (Trip $t) => $t->number, $made)]]);

            return $made;
        });

        $this->notifier->toClients($request->customer_id, 'client_request.approved', [
            'number' => $request->number, 'count' => count($trips),
        ], route('client.requests.index', [], false));

        return $trips;
    }

    public function decline(ClientRequest $request, string $reason, User $user): void
    {
        if (! in_array($request->status, ['new', 'approved'], true)) {
            throw TripRuleException::because('client.request_decided');
        }

        $request->forceFill(['status' => 'declined', 'decline_reason' => mb_substr(trim($reason), 0, 250), 'decided_by' => $user->id, 'decided_at' => now()])->save();
        Audit::record('client_request.declined', $request, ['after' => ['reason' => $request->decline_reason]]);

        $this->notifier->toClients($request->customer_id, 'client_request.declined', [
            'number' => $request->number,
        ], route('client.requests.index', [], false));
    }
}
