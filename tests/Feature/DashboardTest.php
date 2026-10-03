<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Services\Dashboard\DashboardService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the dashboard (Step 7, Scope §6.1)
//  Location: tests/Feature/DashboardTest.php
//  Feature doc: docs/STEP_07_DASHBOARD_REPORTS_NOTIFICATIONS_AUDIT.md
//  The figures come from real trips; users without "dashboard.view"
//  get the simple start page; nothing leaks between companies.
// ══════════════════════════════════════════════════════════════════

class DashboardTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('trip_files');
    }

    /** A trip delivered now: price 10,200 and 3,400 of fuel. */
    private function deliveredTrip(): Trip
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 3400]);
        $this->post("/office/trips/{$trip->id}/deliver", ['pod' => $this->pod(), 'receiver' => 'Store keeper'])->assertSessionHas('success');

        return $trip->fresh();
    }

    private function near(float $expected): \Closure
    {
        return fn ($v) => abs((float) $v - $expected) < 0.01;
    }

    public function test_the_office_start_page_is_the_dashboard_with_the_real_figures(): void
    {
        $trip = $this->deliveredTrip();

        $this->get('/office')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Dashboard')
            ->where('dash.period', 'm')
            ->where('dash.kpis.rev.value', $this->near(10200))
            ->where('dash.kpis.direct.value', $this->near(3400))
            ->where('dash.kpis.dp.value', $this->near(6800))
            ->where('dash.kpis.km.trips', 1)
            ->has('dash.history', 12)
            ->where('dash.history.11.rev', $this->near(10200))
            ->where('dash.history.11.closed', false)
            ->has('dash.actions', 10));

        $this->assertSame('delivered', $trip->status);
    }

    public function test_a_user_without_the_dashboard_permission_gets_the_simple_start_page(): void
    {
        $this->setUpFleet();
        $user = $this->officeUser($this->co, ['trips.view']);

        $this->actingAs($user, 'web')->get('/office')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Office/Home'));
    }

    public function test_the_period_chips_change_the_window(): void
    {
        $this->deliveredTrip();

        $this->get('/office?period=lm')->assertInertia(fn (Assert $page) => $page
            ->where('dash.period', 'lm')->where('dash.kpis.rev.value', $this->near(0)));
        $this->get('/office?period=q')->assertInertia(fn (Assert $page) => $page
            ->where('dash.period', 'q')->where('dash.kpis.rev.value', $this->near(10200)));
        $this->get('/office?period=nonsense')->assertInertia(fn (Assert $page) => $page->where('dash.period', 'm'));
    }

    public function test_the_road_board_and_the_cash_outside_the_safe_show_a_running_trip(): void
    {
        $trip = $this->runningTrip();

        $this->actingAs($this->admin, 'web')->get('/office')->assertInertia(fn (Assert $page) => $page
            ->where('dash.road.active', 1)
            ->where('dash.road.lanes.0.number', $trip->number)
            ->where('dash.road.lanes.0.status', 'on_road')
            ->where('dash.road.lanes.0.custody', $this->near(3000))
            ->where('dash.cash.custody', $this->near(3000))
            ->where('dash.cash.total', $this->near(3000))
            ->where('dash.cash.drivers.0.name', $this->drv->name)
            ->where('dash.fleet.counts.road', 1));
    }

    public function test_the_action_centre_counts_expiring_documents_and_waiting_transfers(): void
    {
        $trip = $this->runningTrip('approval');
        $this->truck->forceFill(['licence_expires_at' => today()->addDays(5), 'insurance_expires_at' => today()->subDay()])->save();
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 2000])->assertSessionHas('success');
        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 500, 'reason' => 'Diesel'])->assertSessionHas('success');

        $this->get('/office')->assertInertia(fn (Assert $page) => $page
            ->where('dash.actions', function ($actions) {
                $by = collect($actions)->keyBy('key');

                return $by['docs']['count'] === 2 && $by['docs']['sub']['n'] === 1 && $by['transfers']['count'] === 1;
            }));
    }

    public function test_nothing_leaks_between_companies(): void
    {
        $this->deliveredTrip();
        $other = $this->companyAdmin($this->company());

        $this->actingAs($other, 'web')->get('/office')->assertInertia(fn (Assert $page) => $page
            ->where('dash.kpis.rev.value', $this->near(0))->where('dash.road.active', 0)->where('dash.cash.total', $this->near(0)));
    }

    public function test_the_printable_dashboard_opens_for_users_with_the_permission_only(): void
    {
        $this->deliveredTrip();
        $this->admin->forceFill(['language' => 'en'])->save(); // this test reads the English wording

        $this->get('/office/dashboard/print')->assertOk()->assertSee('10,200')->assertSee('Print / Save as PDF');

        $user = $this->officeUser($this->co, ['trips.view']);
        $this->actingAs($user, 'web')->get('/office/dashboard/print')->assertForbidden();
    }

    public function test_the_service_marks_the_open_month_as_an_estimate_and_knows_the_cost_mix(): void
    {
        $this->deliveredTrip();

        $dash = Tenant::forCompany($this->co->id, fn () => app(DashboardService::class)->build($this->co->id, 'm'));

        $this->assertNull($dash['kpis']['tp']['value']);            // no G&A rate yet — the screen says so instead of guessing
        $this->assertTrue($dash['history'][11]['closed'] === false);
        $this->assertSame(3400.0, round($dash['costMix']['total'], 2));
        $this->assertSame('fuel', $dash['costMix']['parts'][0]['code']);
    }
}
