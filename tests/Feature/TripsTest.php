<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\Vehicle;
use App\Services\Trips\Actor;
use App\Services\Trips\TripService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: trips and their lifecycle (Step 3, Scope §6.3)
//  Location: tests/Feature/TripsTest.php
//  Feature doc: docs/STEP_03_TRIPS_AND_WALLETS.md §1–§2
// ══════════════════════════════════════════════════════════════════

class TripsTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    public function test_a_new_trip_takes_the_agreed_price_the_route_and_the_suggested_custody(): void
    {
        $this->setUpFleet();

        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData())->assertSessionHasNoErrors()->assertRedirect();

        $trip = Trip::query()->firstOrFail();
        $this->assertSame('T-00001', $trip->number);
        $this->assertSame('planned', $trip->status);
        $this->assertEquals(10200, $trip->freight_price);
        $this->assertSame('rate_card', $trip->price_source);
        $this->assertSame(450, $trip->km);
        // Cash road costs 2,810 × 1.05 = 2,950.5 → rounded up to 500 → 3,000
        $this->assertEquals(3000, $trip->custody_planned);
        // The truck's usual driver is suggested.
        $this->assertEquals($this->drv->id, $trip->driver_id);
        // The company's default transfer policy.
        $this->assertSame('limit', $trip->transfer_policy);
        $this->assertEquals(1000, $trip->auto_transfer_limit);
        // The standard budget is copied, so later route changes do not rewrite it.
        $this->assertEquals(3450, $trip->standard_budget[$this->cats['fuel']]);
        $this->assertTrue(AuditLog::query()->where('action', 'trip.created')->exists());
        $this->assertSame(1, $trip->events()->where('type', 'created')->count());

        $this->post('/office/trips', $this->tripData(['vehicle_id' => Vehicle::factory()->for($this->co)->create(['driver_id' => $this->driver($this->co)->id])->id]));
        $this->assertSame('T-00002', Trip::query()->latest('id')->value('number'));
    }

    public function test_typing_another_price_needs_the_edit_price_permission(): void
    {
        $this->setUpFleet();
        $user = $this->officeUser($this->co, ['trips.view', 'trips.create']);

        $this->actingAs($user, 'web')->post('/office/trips', $this->tripData(['freight_price' => 12000]))->assertSessionHas('error');
        $this->assertSame(0, Trip::query()->count());

        $priced = $this->officeUser($this->co, ['trips.view', 'trips.create', 'trips.edit_price']);
        $this->actingAs($priced, 'web')->post('/office/trips', $this->tripData(['freight_price' => 12000]))->assertSessionHasNoErrors();
        $trip = Trip::query()->firstOrFail();
        $this->assertEquals(12000, $trip->freight_price);
        $this->assertSame('manual', $trip->price_source);
    }

    public function test_a_route_without_an_agreed_price_needs_a_typed_price(): void
    {
        $this->setUpFleet();
        $other = Customer::factory()->for($this->co)->create();

        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData(['customer_id' => $other->id]))->assertSessionHas('error');
        $this->post('/office/trips', $this->tripData(['customer_id' => $other->id, 'freight_price' => 9000]))->assertSessionHasNoErrors();
        $this->assertEquals(9000, Trip::query()->firstOrFail()->freight_price);
    }

    public function test_a_truck_in_maintenance_or_with_a_booked_next_trip_cannot_be_booked(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $this->truck->update(['status' => 'maintenance']);
        $this->post('/office/trips', $this->tripData())->assertSessionHas('error');

        $this->truck->update(['status' => 'available']);
        $this->travel(10)->seconds(); // the same form twice within seconds is ignored as a double-click
        $this->post('/office/trips', $this->tripData())->assertSessionHasNoErrors();
        $this->assertSame(1, Trip::query()->count());
        // A second planned trip for the same truck is refused…
        $this->travel(10)->seconds();
        $this->post('/office/trips', $this->tripData())->assertSessionHas('error');
        $this->assertSame(1, Trip::query()->count());

        // …but once the first one is running, its "next trip" can be booked.
        Tenant::forCompany($this->co->id, function () {
            $service = app(TripService::class);
            $trip = Trip::query()->firstOrFail();
            $service->accept($trip, Actor::user($this->admin));
        });
        $this->travel(10)->seconds();
        $this->post('/office/trips', $this->tripData())->assertSessionHasNoErrors();
        $this->assertSame(2, Trip::query()->count());
    }

    public function test_a_hired_truck_gets_no_custody_and_its_owners_fee_is_a_company_cost(): void
    {
        $this->setUpFleet();
        $hired = Vehicle::factory()->hired()->for($this->co)->create();

        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData(['vehicle_id' => $hired->id, 'custody_planned' => 3000, 'hire_fee' => 7800]))
            ->assertSessionHasNoErrors();

        $trip = Trip::query()->firstOrFail();
        $this->assertTrue($trip->is_hired);
        $this->assertNull($trip->driver_id);
        $this->assertEquals(0, $trip->custody_planned);

        $fee = TripExpense::query()->firstOrFail();
        $this->assertSame('company', $fee->paid_from);
        $this->assertEquals(7800, $fee->amount);
        $this->assertEquals($this->cats['hire'], $fee->expense_category_id);
    }

    public function test_the_trip_moves_one_step_at_a_time_and_delivery_needs_its_photo(): void
    {
        Storage::fake('trip_files');
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData());
        $trip = Trip::query()->firstOrFail();
        $url = "/office/trips/{$trip->id}";

        $this->post("{$url}/step", ['step' => 'depart'])->assertSessionHas('error');       // not yet
        $this->post("{$url}/step", ['step' => 'accept'])->assertSessionHas('success');
        $this->post("{$url}/step", ['step' => 'loading'])->assertSessionHas('error');      // custody first
        $this->post("{$url}/custody", ['amount' => 3000])->assertSessionHas('success');
        // The office ignores the very same form sent twice within a few seconds; wait it out.
        $this->travel(10)->seconds();
        $this->post("{$url}/step", ['step' => 'loading'])->assertSessionHas('success');
        $this->post("{$url}/step", ['step' => 'depart'])->assertSessionHas('success');

        $this->post("{$url}/deliver", ['receiver' => 'Store keeper'])->assertSessionHasErrors('pod');
        $this->post("{$url}/deliver", ['pod' => $this->pod(), 'receiver' => 'Store keeper'])->assertSessionHas('success');

        $trip->refresh();
        $this->assertSame('delivered', $trip->status);
        $this->assertNotNull($trip->pod_path);
        Storage::disk('trip_files')->assertExists($trip->pod_path);
        $this->assertEqualsCanonicalizing(
            ['created', 'accepted', 'custody_issued', 'loading', 'departed', 'delivered'],
            $trip->events()->pluck('type')->all(),
        );

        // The photo is shown only through the permission-checked address.
        $this->get("{$url}/pod")->assertOk();
    }

    public function test_the_trip_file_shows_its_figures(): void
    {
        $trip = $this->runningTrip();

        $this->actingAs($this->admin, 'web')->get("/office/trips/{$trip->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Trips/Show')
            ->where('trip.number', 'T-00001')
            ->where('trip.status', 'on_road')
            ->where('figures.revenue', 10200)
            ->where('figures.revenue_per_km', 22.67)
            ->where('wallets.custody.issued', 3000)
            ->where('wallets.custody.balance', 3000)
            ->where('nextStep', 'deliver'));
    }

    public function test_changing_the_price_is_audited_and_needs_the_permission(): void
    {
        $trip = $this->runningTrip();
        $user = $this->officeUser($this->co, ['trips.view', 'trips.edit']);

        $this->actingAs($user, 'web')->patch("/office/trips/{$trip->id}", ['freight_price' => 11000])->assertSessionHas('error');
        $this->assertEquals(10200, $trip->fresh()->freight_price);

        $this->actingAs($this->admin, 'web')->patch("/office/trips/{$trip->id}", ['freight_price' => 11000])->assertSessionHas('success');
        $this->assertEquals(11000, $trip->fresh()->freight_price);
        $log = AuditLog::query()->where('action', 'trip.price_changed')->firstOrFail();
        $this->assertEquals(10200, $log->changes['before']['price']);
        $this->assertEquals(11000, $log->changes['after']['price']);
    }

    public function test_a_trip_can_be_cancelled_only_before_money_moves(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData());
        $trip = Trip::query()->firstOrFail();

        $this->post("/office/trips/{$trip->id}/step", ['step' => 'accept']);
        $this->post("/office/trips/{$trip->id}/custody", ['amount' => 1000]);
        $this->post("/office/trips/{$trip->id}/cancel", ['reason' => 'Client postponed'])->assertSessionHas('error');
        $this->assertSame('accepted', $trip->fresh()->status);

        $this->post('/office/trips', $this->tripData(['vehicle_id' => Vehicle::factory()->for($this->co)->create(['driver_id' => $this->driver($this->co)->id])->id]));
        $second = Trip::query()->latest('id')->firstOrFail();
        $this->post("/office/trips/{$second->id}/cancel", ['reason' => 'Client postponed'])->assertSessionHas('success');
        $this->assertSame('cancelled', $second->fresh()->status);
    }

    public function test_the_list_filters_and_adds_up(): void
    {
        $trip = $this->runningTrip();

        $this->actingAs($this->admin, 'web')->get('/office/trips?status=running')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Trips/Index')
            ->where('trips.total', 1)
            ->where('trips.data.0.number', $trip->number)
            ->where('trips.data.0.cash.custody', 3000)
            ->where('totals.revenue', 10200)
            ->where('counts.running', 1));

        $this->get('/office/trips?status=settled')->assertInertia(fn (Assert $page) => $page->where('trips.total', 0));
        $this->get('/office/trips?search=T-00001')->assertInertia(fn (Assert $page) => $page->where('trips.total', 1));
    }

    public function test_excel_export_and_the_printable_trip_order(): void
    {
        $trip = $this->runningTrip();

        $this->actingAs($this->admin, 'web')->get('/office/trips/export')->assertOk()->assertDownload();

        $html = $this->get("/office/trips/{$trip->id}/print")->assertOk()->getContent();
        $this->assertStringContainsString($trip->number, $html);
        // The trip order goes to the driver: no price on it.
        $this->assertStringNotContainsString('10,200', $html);
    }

    public function test_trips_need_the_trips_permission(): void
    {
        $trip = $this->runningTrip();

        $this->actingAs($this->officeUser($this->co, ['vehicles.view']), 'web')->get('/office/trips')->assertForbidden();
        $this->get("/office/trips/{$trip->id}")->assertForbidden();

        $viewer = $this->officeUser($this->co, ['trips.view']);
        $this->actingAs($viewer, 'web')->get("/office/trips/{$trip->id}")->assertOk();
        $this->post('/office/trips', $this->tripData())->assertForbidden();
        $this->post("/office/trips/{$trip->id}/custody", ['amount' => 500])->assertForbidden();
    }

    public function test_the_over_budget_flag_follows_the_companys_own_percent(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');

        // Fuel standard 3,450. Spending 4,140 is 20% above it.
        $this->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'company', 'amount' => 4140]);

        // Default 15% → flagged.
        $this->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $page) => $page
            ->where('figures.budget.percent', 15)
            ->where('figures.budget.rows.0.over', true));

        // The company raises it to 25% in Company settings → no longer flagged.
        $this->put('/office/settings', [
            'default_transfer_policy' => 'limit', 'auto_transfer_limit' => 1000, 'custody_buffer_percent' => 5, 'over_budget_percent' => 25,
            'personal_spend_to_advance' => true, 'month_split_rule' => 'hours', 'ga_basis' => 'own_km', 'ga_rate_estimate' => null,
            'receipt_photo_required' => true, 'capture_location' => true, 'offline_mode' => true, 'max_hours_without_sync' => 12,
            'diesel_price' => 20.5, 'currency' => 'EGP', 'default_language' => 'ar', 'default_theme' => 'dark',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertTrue(AuditLog::query()->where('action', 'settings.updated')->exists());

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $page) => $page
            ->where('figures.budget.percent', 25)
            ->where('figures.budget.rows.0.over', false));
    }

    public function test_another_companys_trip_is_not_found(): void
    {
        $trip = $this->runningTrip();
        $stranger = $this->companyAdmin();

        $this->actingAs($stranger, 'web')->get("/office/trips/{$trip->id}")->assertNotFound();
        $this->post("/office/trips/{$trip->id}/custody", ['amount' => 500])->assertNotFound();
        $this->get("/office/trips/{$trip->id}/pod")->assertNotFound();
    }
}
