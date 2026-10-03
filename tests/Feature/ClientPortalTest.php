<?php

namespace Tests\Feature;

use App\Models\ClientComplaint;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Customer;
use App\Models\RateCard;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\TripRating;
use App\Models\Vehicle;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Services\Trips\TripService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: Client portal and client requests (Step 5)
//  Location: tests/Feature/ClientPortalTest.php
//  Feature doc: docs/STEP_05_CLIENT_PORTAL.md
//
//  Scope §6.2, §7, §9, §11: a client sees only his own customer; a
//  request is sent, assigned (one trip per truck) or declined; the
//  cash the driver took is confirmed / disputed from the client side
//  (and the client can record cash he handed over); a delivered trip
//  is rated once; complaints are answered; the account admin manages
//  his colleagues; the bell tells each side what happened.
// ══════════════════════════════════════════════════════════════════

class ClientPortalTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    private ClientUser $client;

    private function setUpClient(): void
    {
        $this->setUpFleet();
        $this->client = ClientUser::factory()->for($this->customer)->create(['company_id' => $this->co->id, 'is_account_admin' => true]);
    }

    /** A trip of the set-up customer, on the road. */
    private function onRoad(): Trip
    {
        return Tenant::forCompany($this->co->id, function () {
            $service = app(TripService::class);
            $trip = $service->create($this->tripData(), $this->admin);
            $actor = Actor::user($this->admin);
            $service->accept($trip, $actor);
            $service->issueCustody($trip, 3000, $actor); // loading cannot start before the custody is handed over
            $service->startLoading($trip, $actor);
            $service->depart($trip, $actor);

            return $trip->fresh();
        });
    }

    /** A trip of ANOTHER client of the same company. */
    private function strangersTrip(): Trip
    {
        $other = Customer::factory()->for($this->co)->create();
        RateCard::query()->create(['company_id' => $this->co->id, 'customer_id' => $other->id, 'trip_route_id' => $this->route->id, 'price' => 9000]);
        $drv = $this->driver($this->co);
        $truck = Vehicle::factory()->for($this->co)->create(['driver_id' => $drv->id]);

        return Tenant::forCompany($this->co->id, fn () => app(TripService::class)->create($this->tripData(['customer_id' => $other->id, 'vehicle_id' => $truck->id]), $this->admin));
    }

    private function requestData(array $over = []): array
    {
        // The old single-route overrides (trip_route_id / trucks_count) are mapped onto ONE line.
        $line = ['trip_route_id' => $over['trip_route_id'] ?? $this->route->id, 'trucks_count' => $over['trucks_count'] ?? 2];
        unset($over['trip_route_id'], $over['trucks_count']);

        return array_merge(['lines' => [$line], 'loading_at' => now()->addDays(2)->format('Y-m-d H:i'), 'notes' => 'Gate 4'], $over);
    }

    private function secondTruck(): Vehicle
    {
        return Vehicle::factory()->for($this->co)->create(['driver_id' => $this->driver($this->co)->id]);
    }

    // ── Isolation ──────────────────────────────────────────────────

    public function test_a_client_sees_only_his_own_customers_trips(): void
    {
        $this->setUpClient();
        $mine = $this->onRoad();
        $theirs = $this->strangersTrip();

        $this->actingAs($this->client, 'client')->get('/client/shipments')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Client/Shipments/Index')->has('trips.data', 1)->where('trips.data.0.number', $mine->number));

        $this->get("/client/shipments/{$mine->id}")->assertOk();
        $this->get("/client/shipments/{$theirs->id}")->assertNotFound();
        $this->get("/client/shipments/{$theirs->id}/pod")->assertNotFound();
        $this->post("/client/shipments/{$theirs->id}/rating", ['stars' => 5])->assertNotFound();
    }

    public function test_a_client_never_gets_costs_or_profit(): void
    {
        $this->setUpClient();
        $trip = $this->onRoad();

        $this->actingAs($this->client, 'client')->get("/client/shipments/{$trip->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Client/Shipments/Show')
                ->has('trip.price')->missing('trip.cost_total')->missing('trip.custody_planned')->missing('wallets')->missing('figures'));
    }

    public function test_the_office_cannot_be_reached_from_the_client_portal(): void
    {
        $this->setUpClient();

        $this->actingAs($this->client, 'client')->get('/office/client-requests')->assertRedirect();
    }

    // ── Requests ───────────────────────────────────────────────────

    public function test_a_client_sends_a_request_at_his_agreed_price_and_the_office_is_told(): void
    {
        $this->setUpClient();

        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData())->assertSessionHasNoErrors()->assertRedirect('/client/requests');

        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('R-00001', $r->number);
        $this->assertSame('new', $r->status);
        $this->assertSame(2, $r->trucks_count);
        $this->assertCount(1, $r->lines);
        $this->assertEquals(10200, $r->lines->first()->unit_price);
        $this->assertEquals(20400, $r->expectedTotal());
        $this->assertSame($this->customer->id, $r->customer_id);
        $this->assertSame('client_request.new', $this->admin->notifications()->first()->data['key']);
    }

    public function test_a_client_cannot_ask_for_a_route_without_an_agreed_price(): void
    {
        $this->setUpClient();
        $unpriced = \App\Models\TripRoute::factory()->for($this->co)->create();

        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['trip_route_id' => $unpriced->id]))->assertSessionHasErrors('lines.0.trip_route_id');
        $this->assertSame(0, ClientRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_the_loading_time_must_be_in_the_future(): void
    {
        $this->setUpClient();

        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['loading_at' => now()->subDay()->format('Y-m-d H:i')]))
            ->assertSessionHas('error');
        $this->assertSame(0, ClientRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_a_client_can_withdraw_a_new_request_but_not_a_decided_one(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['trucks_count' => 1]));
        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();

        $this->post("/client/requests/{$r->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $r->fresh()->status);

        $this->post("/client/requests/{$r->id}/cancel")->assertSessionHas('error');
    }

    public function test_the_office_assigns_one_trip_per_truck_and_the_client_is_told(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData());
        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();
        $second = $this->secondTruck();

        $this->actingAs($this->admin, 'web')->post("/office/client-requests/{$r->id}/assign", [
            'assignments' => [['vehicle_id' => $this->truck->id], ['vehicle_id' => $second->id]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('assigned', $r->fresh()->status);
        $trips = Trip::query()->withoutGlobalScopes()->where('client_request_id', $r->id)->orderBy('id')->get();
        $this->assertCount(2, $trips);
        $this->assertTrue($trips->every(fn (Trip $t) => $t->status === 'planned' && (float) $t->freight_price === 10200.0 && $t->customer_id === $this->customer->id));
        $this->assertSame('client_request.approved', $this->client->notifications()->first()->data['key']);

        $this->actingAs($this->client, 'client')->get('/client/requests')->assertInertia(fn (Assert $p) => $p->has('requests.data.0.trips', 2));
    }

    public function test_the_office_can_approve_now_and_assign_the_trucks_later(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['loading_at' => now()->addDays(10)->format('Y-m-d H:i'), 'trucks_count' => 1]));
        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();

        // Step 1: yes — no trips yet, the client is told trucks come later.
        $this->actingAs($this->admin, 'web')->post("/office/client-requests/{$r->id}/approve")->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(0, Trip::query()->withoutGlobalScopes()->count());
        $this->assertSame('client_request.accepted', $this->client->notifications()->first()->data['key']);

        // It waits in its own tab, not flagged urgent (10 days away).
        $this->get('/office/client-requests?tab=approved')->assertInertia(fn (Assert $p) => $p->has('requests', 1)->where('requests.0.urgent', false)->where('tiles.approved', 1)->has('options.vehicles'));
        $this->get('/office')->assertInertia(fn (Assert $p) => $p->where('money.to_assign', 1)->where('money.requests', 0));

        // The client sees "approved", no trucks yet.
        $this->actingAs($this->client, 'client')->get('/client/requests')->assertInertia(fn (Assert $p) => $p->where('requests.data.0.status', 'approved')->has('requests.data.0.trips', 0));

        // Step 2: later, the trucks.
        $this->actingAs($this->admin, 'web')->post("/office/client-requests/{$r->id}/assign", ['assignments' => [['vehicle_id' => $this->truck->id]]])->assertSessionHasNoErrors();
        $this->assertSame('assigned', $r->fresh()->status);
        $this->assertSame(1, Trip::query()->withoutGlobalScopes()->where('client_request_id', $r->id)->count());
        $keys = $this->client->notifications()->get()->pluck('data.key')->all();
        $this->assertContains('client_request.accepted', $keys);
        $this->assertContains('client_request.approved', $keys); // trucks assigned

        // Once trucks are assigned it cannot be approved, declined or withdrawn again.
        $this->post("/office/client-requests/{$r->id}/approve")->assertSessionHas('error');
        $this->post("/office/client-requests/{$r->id}/decline", ['reason' => 'x'])->assertSessionHas('error');
    }

    public function test_an_approved_request_loading_soon_without_trucks_is_flagged_and_can_still_be_declined_or_withdrawn(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['loading_at' => now()->addDay()->format('Y-m-d H:i'), 'trucks_count' => 1]));
        $this->post('/client/requests', $this->requestData(['loading_at' => now()->addDays(20)->format('Y-m-d H:i'), 'trucks_count' => 1]));
        [$soon, $later] = ClientRequest::query()->withoutGlobalScopes()->orderBy('id')->get()->all();

        $this->actingAs($this->admin, 'web')->post("/office/client-requests/{$soon->id}/approve");
        $this->post("/office/client-requests/{$later->id}/approve");

        $this->get('/office/client-requests?tab=approved')->assertInertia(fn (Assert $p) => $p
            ->where('requests.0.number', $soon->number)->where('requests.0.urgent', true)->where('requests.1.urgent', false));

        $this->post("/office/client-requests/{$soon->id}/decline", ['reason' => 'No trucks'])->assertSessionHasNoErrors();
        $this->assertSame('declined', $soon->fresh()->status);

        $this->actingAs($this->client, 'client')->post("/client/requests/{$later->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $later->fresh()->status);
    }

    public function test_only_users_who_may_approve_can_approve(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['trucks_count' => 1]));
        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->officeUser($this->co, ['client_requests.view']), 'web')->post("/office/client-requests/{$r->id}/approve")->assertForbidden();
        $this->assertSame('new', $r->fresh()->status);
    }

    public function test_one_request_can_ask_for_trucks_of_different_weights_and_each_gets_its_own_price(): void
    {
        $this->setUpClient();
        $light = \App\Models\TripRoute::factory()->for($this->co)->create(['origin_ar' => $this->route->origin_ar, 'destination_ar' => $this->route->destination_ar, 'weight_tons' => 1]);
        RateCard::query()->create(['company_id' => $this->co->id, 'customer_id' => $this->customer->id, 'trip_route_id' => $light->id, 'price' => 3000]);

        $this->actingAs($this->client, 'client')->post('/client/requests', [
            'lines' => [['trip_route_id' => $this->route->id, 'trucks_count' => 4], ['trip_route_id' => $light->id, 'trucks_count' => 2]],
            'loading_at' => now()->addDays(3)->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        $r = ClientRequest::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(6, $r->trucks_count);
        $this->assertCount(2, $r->lines);
        $this->assertEquals(4 * 10200 + 2 * 3000, $r->expectedTotal());

        $trucks = [$this->truck];
        for ($i = 1; $i < 6; $i++) {
            $trucks[] = $this->secondTruck();
        }
        $this->actingAs($this->admin, 'web')->post("/office/client-requests/{$r->id}/assign", [
            'assignments' => array_map(fn ($t) => ['vehicle_id' => $t->id], $trucks),
        ])->assertSessionHasNoErrors();

        $trips = Trip::query()->withoutGlobalScopes()->where('client_request_id', $r->id)->orderBy('id')->get();
        $this->assertCount(6, $trips);
        $this->assertSame(4, $trips->where('trip_route_id', $this->route->id)->count());
        $this->assertSame(2, $trips->where('trip_route_id', $light->id)->count());
        $this->assertTrue($trips->where('trip_route_id', $light->id)->every(fn (Trip $t) => (float) $t->freight_price === 3000.0));
        $this->assertTrue($trips->where('trip_route_id', $this->route->id)->every(fn (Trip $t) => (float) $t->freight_price === 10200.0));
    }

    public function test_the_same_weight_twice_in_one_request_is_merged_into_one_line(): void
    {
        $this->setUpClient();

        $this->actingAs($this->client, 'client')->post('/client/requests', [
            'lines' => [['trip_route_id' => $this->route->id, 'trucks_count' => 2], ['trip_route_id' => $this->route->id, 'trucks_count' => 3]],
            'loading_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertCount(1, $r->lines);
        $this->assertSame(5, $r->lines->first()->trucks_count);
        $this->assertSame(5, $r->trucks_count);
    }

    public function test_a_request_without_lines_is_refused(): void
    {
        $this->setUpClient();

        $this->actingAs($this->client, 'client')->post('/client/requests', ['lines' => [], 'loading_at' => now()->addDays(2)->format('Y-m-d H:i')])->assertSessionHasErrors('lines');
        $this->assertSame(0, ClientRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_assigning_needs_exactly_the_trucks_asked_and_changes_nothing_if_it_fails(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData());
        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin, 'web')->post("/office/client-requests/{$r->id}/assign", ['assignments' => [['vehicle_id' => $this->truck->id]]])->assertSessionHas('error');
        $this->post("/office/client-requests/{$r->id}/assign", ['assignments' => [['vehicle_id' => $this->truck->id], ['vehicle_id' => $this->truck->id]]])->assertSessionHas('error');

        $this->assertSame('new', $r->fresh()->status);
        $this->assertSame(0, Trip::query()->withoutGlobalScopes()->count());
    }

    public function test_declining_keeps_the_reason_and_tells_the_client(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['trucks_count' => 1]));
        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin, 'web')->post("/office/client-requests/{$r->id}/decline", ['reason' => 'No trucks that day'])->assertSessionHasNoErrors();

        $this->assertSame('declined', $r->fresh()->status);
        $this->assertSame('No trucks that day', $r->fresh()->decline_reason);
        $this->assertSame('client_request.declined', $this->client->notifications()->first()->data['key']);

        // Already answered: a second answer is refused.
        $this->post("/office/client-requests/{$r->id}/decline", ['reason' => 'again'])->assertSessionHas('error');
    }

    public function test_only_users_with_the_right_permission_can_answer_requests(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['trucks_count' => 1]));
        $r = ClientRequest::query()->withoutGlobalScopes()->firstOrFail();
        $viewer = $this->officeUser($this->co, ['client_requests.view']);

        $this->actingAs($viewer, 'web')->get('/office/client-requests')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Office/ClientRequests/Index')->has('requests', 1)->where('options', null));
        $this->post("/office/client-requests/{$r->id}/decline", ['reason' => 'x'])->assertForbidden();
        $this->post("/office/client-requests/{$r->id}/assign", ['assignments' => [['vehicle_id' => $this->truck->id]]])->assertForbidden();
    }

    // ── Two-sided cash ─────────────────────────────────────────────

    private function driverTookCash(Trip $trip, float $amount = 1500): TripCollection
    {
        return Tenant::forCompany($this->co->id, fn () => app(CollectionService::class)->record($trip, $amount, 'driver', Actor::driver($this->drv)));
    }

    public function test_the_client_confirms_cash_the_driver_took(): void
    {
        $this->setUpClient();
        $trip = $this->onRoad();
        $c = $this->driverTookCash($trip);
        $this->assertSame('awaiting_client', $c->fresh()->state());
        $this->assertSame('cash.to_confirm', $this->client->notifications()->first()->data['key']);

        $this->actingAs($this->client, 'client')->post("/client/cash/{$c->id}/confirm")->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $c->fresh()->state());
        $this->assertEquals(1500, $this->balances($trip)['collections']);
    }

    public function test_a_disputed_amount_does_not_count_until_management_decides_and_the_office_is_told(): void
    {
        $this->setUpClient();
        $trip = $this->onRoad();
        $c = $this->driverTookCash($trip);
        $this->assertEquals(1500, $this->balances($trip)['collections']);

        $this->actingAs($this->client, 'client')->post("/client/cash/{$c->id}/dispute", ['note' => 'I gave 1,000'])->assertSessionHasNoErrors();

        $this->assertSame('disputed', $c->fresh()->state());
        $this->assertSame('client', $c->fresh()->disputed_by);
        $this->assertEquals(0, $this->balances($trip)['collections']);
        $this->assertSame('cash.disputed_office', $this->admin->notifications()->first()->data['key']);
    }

    public function test_an_objection_needs_a_reason(): void
    {
        $this->setUpClient();
        $c = $this->driverTookCash($this->onRoad());

        $this->actingAs($this->client, 'client')->post("/client/cash/{$c->id}/dispute", ['note' => ''])->assertSessionHasErrors('note');
    }

    public function test_a_client_cannot_touch_another_clients_cash(): void
    {
        $this->setUpClient();
        $theirs = $this->strangersTrip();
        Tenant::forCompany($this->co->id, function () use ($theirs) {
            $svc = app(TripService::class);
            $svc->accept($theirs, Actor::user($this->admin));
        });
        $foreign = Tenant::forCompany($this->co->id, fn () => TripCollection::query()->create([
            'company_id' => $this->co->id, 'trip_id' => $theirs->id, 'driver_id' => $theirs->driver_id, 'customer_id' => $theirs->customer_id,
            'amount' => 100, 'received_at' => now(), 'recorded_by' => 'driver', 'driver_confirmed_at' => now(),
        ]));

        $this->actingAs($this->client, 'client')->post("/client/cash/{$foreign->id}/confirm")->assertNotFound();
        $this->post("/client/cash/{$foreign->id}/dispute", ['note' => 'x'])->assertNotFound();
        $this->get('/client/cash')->assertInertia(fn (Assert $p) => $p->has('rows', 0));
    }

    public function test_the_client_records_cash_he_handed_over_and_the_driver_must_confirm(): void
    {
        $this->setUpClient();
        $trip = $this->onRoad();

        $this->actingAs($this->client, 'client')->post('/client/cash', ['trip_id' => $trip->id, 'amount' => 700, 'note' => 'At the gate'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $c = TripCollection::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('client', $c->recorded_by);
        $this->assertSame('awaiting_driver', $c->state());
        $this->assertEquals(0, $this->balances($trip)['collections']);

        $this->get('/client/cash')->assertInertia(fn (Assert $p) => $p->where('totals.waiting', fn ($v) => (float) $v === 700.0)->where('totals.confirmed', fn ($v) => (float) $v === 0.0));
    }

    public function test_a_client_not_allowed_to_pay_cash_cannot_record_it(): void
    {
        $this->setUpClient();
        $trip = $this->onRoad();
        $this->customer->forceFill(['may_pay_driver_cash' => false])->save();

        $this->actingAs($this->client, 'client')->post('/client/cash', ['trip_id' => $trip->id, 'amount' => 700])->assertSessionHas('error');
        $this->assertSame(0, TripCollection::query()->withoutGlobalScopes()->count());
    }

    // ── Delivery, rating, complaints ───────────────────────────────

    public function test_delivery_tells_the_client_and_he_can_rate_the_trip_once(): void
    {
        $this->setUpClient();
        $trip = $this->onRoad();

        $this->actingAs($this->client, 'client')->post("/client/shipments/{$trip->id}/rating", ['stars' => 5])->assertSessionHas('error'); // not delivered yet

        Tenant::forCompany($this->co->id, fn () => app(TripService::class)->deliver($trip, 'trips/pod.jpg', 'Store keeper', Actor::user($this->admin)));
        $this->assertSame('trip.delivered', $this->client->notifications()->first()->data['key']);

        $this->post("/client/shipments/{$trip->id}/rating", ['stars' => 4, 'comment' => 'On time'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post("/client/shipments/{$trip->id}/rating", ['stars' => 1])->assertSessionHas('error');
        $this->post("/client/shipments/{$trip->id}/rating", ['stars' => 9])->assertSessionHasErrors('stars');

        $rating = TripRating::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(4, $rating->stars);
        $this->assertSame($this->client->id, $rating->client_user_id);

        $this->actingAs($this->admin, 'web')->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $p) => $p->where('feedback.rating.stars', 4));
        $this->get('/office/client-requests?tab=feedback')->assertInertia(fn (Assert $p) => $p->has('ratings', 1)->where('average', fn ($v) => (float) $v === 4.0));
    }

    public function test_a_complaint_reaches_the_office_and_the_reply_reaches_the_client(): void
    {
        $this->setUpClient();
        $trip = $this->onRoad();

        $this->actingAs($this->client, 'client')->post('/client/feedback', ['trip_id' => $trip->id, 'subject' => 'Late', 'body' => 'Two hours late'])->assertSessionHasNoErrors();
        $c = ClientComplaint::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('open', $c->status);
        $this->assertSame($trip->id, $c->trip_id);
        $this->assertSame('complaint.new', $this->admin->notifications()->first()->data['key']);

        $this->actingAs($this->admin, 'web')->post("/office/complaints/{$c->id}/reply", ['reply' => 'Sorry — traffic.'])->assertSessionHasNoErrors();
        $this->assertSame('answered', $c->fresh()->status);
        $this->assertSame('complaint.answered', $this->client->notifications()->first()->data['key']);

        $this->actingAs($this->client, 'client')->get('/client/feedback')->assertInertia(fn (Assert $p) => $p->where('complaints.0.reply', 'Sorry — traffic.'));
    }

    public function test_a_complaint_cannot_be_about_another_clients_trip(): void
    {
        $this->setUpClient();
        $theirs = $this->strangersTrip();

        $this->actingAs($this->client, 'client')->post('/client/feedback', ['trip_id' => $theirs->id, 'subject' => 'x', 'body' => 'y'])->assertNotFound();
    }

    // ── Statement, prices, home ────────────────────────────────────

    public function test_statement_prices_and_home_show_only_his_figures(): void
    {
        $this->setUpClient();
        $trip = $this->onRoad();
        $trip->forceFill(['loading_at' => now()->startOfMonth()->addHours(8)])->save(); // inside this month, whatever day the test runs
        $this->strangersTrip();
        Tenant::forCompany($this->co->id, fn () => app(TripService::class)->deliver($trip, 'trips/pod.jpg', null, Actor::user($this->admin)));

        $this->actingAs($this->client, 'client')->get('/client/statement')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('trips', 1)->where('totals.delivered', fn ($v) => (float) $v === 10200.0));
        $this->get('/client/prices')->assertInertia(fn (Assert $p) => $p->has('prices', 1)->where('prices.0.price', fn ($v) => (float) $v === 10200.0));
        $this->get('/client')->assertInertia(fn (Assert $p) => $p->where('kpis.month', 1)->where('kpis.cost', fn ($v) => (float) $v === 10200.0)->where('kpis.rating', null));
    }

    public function test_the_statement_exports_to_excel_and_prints(): void
    {
        $this->setUpClient();
        $this->onRoad();

        $this->actingAs($this->client, 'client')->get('/client/statement/export')->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get('/client/statement/print')->assertOk()->assertSee('EGP');
    }

    // ── Colleagues ─────────────────────────────────────────────────

    public function test_the_account_admin_adds_and_suspends_colleagues_others_cannot(): void
    {
        Notification::fake();
        $this->setUpClient();

        $this->actingAs($this->client, 'client')->post('/client/team', ['name' => 'Sara', 'email' => 'sara@delta.test'])->assertSessionHasNoErrors();
        $sara = ClientUser::query()->withoutGlobalScopes()->where('email', 'sara@delta.test')->firstOrFail();
        $this->assertSame($this->customer->id, $sara->customer_id);
        $this->assertFalse($sara->is_account_admin);

        $this->post("/client/team/{$sara->id}/toggle")->assertSessionHasNoErrors();
        $this->assertFalse($sara->fresh()->is_active);
        $this->post("/client/team/{$this->client->id}/toggle")->assertSessionHas('error');

        $member = ClientUser::factory()->for($this->customer)->create(['company_id' => $this->co->id]);
        $this->actingAs($member, 'client')->post('/client/team', ['name' => 'X', 'email' => 'x@delta.test'])->assertForbidden();
        $this->post("/client/team/{$sara->id}/toggle")->assertForbidden();
        $this->get('/client/team')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManage', false)->has('users', 3));
    }

    // ── The bell ───────────────────────────────────────────────────

    public function test_the_bell_lists_and_marks_notifications_for_the_signed_in_person_only(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['trucks_count' => 1]));

        $this->actingAs($this->admin, 'web')->get('/office')->assertInertia(fn (Assert $p) => $p->where('notifications.unread', 1)->where('notifications.items.0.key', 'client_request.new'));

        $id = $this->admin->notifications()->first()->id;
        // The client cannot mark the office's notification as read.
        $this->actingAs($this->client, 'client')->postJson("/client/notifications/{$id}/read")->assertOk();
        $this->assertNull($this->admin->notifications()->first()->read_at);

        $this->actingAs($this->admin, 'web')->postJson("/office/notifications/{$id}/read")->assertOk();
        $this->assertNotNull($this->admin->notifications()->first()->read_at);

        $this->postJson('/office/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->admin->unreadNotifications()->count());
    }

    public function test_the_office_home_counts_what_clients_wait_for(): void
    {
        $this->setUpClient();
        $this->actingAs($this->client, 'client')->post('/client/requests', $this->requestData(['trucks_count' => 1]));
        $this->post('/client/feedback', ['subject' => 'Hello', 'body' => 'World']);

        $this->actingAs($this->admin, 'web')->get('/office')->assertInertia(fn (Assert $p) => $p->where('money.requests', 1)->where('money.complaints', 1));
    }
}
