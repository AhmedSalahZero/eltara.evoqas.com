<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GaEntry;
use App\Models\MaintenanceJob;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the maintenance log
//  Location: tests/Feature/MaintenanceTest.php
//  An open job holds the truck (no new booking) and the dashboard
//  counts it. Finishing or deleting the last open job releases it.
//  A job recorded already done does not. The cost appears on the
//  maintenance report and nowhere in trip expenses or month-close G&A.
// ══════════════════════════════════════════════════════════════════

class MaintenanceTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    public function test_a_finished_periodic_service_does_not_hold_the_truck_and_raises_the_odometer(): void
    {
        $this->setUpFleet();

        $this->actingAs($this->admin, 'web')->post('/office/maintenance', $this->periodic())->assertSessionHas('success');

        $job = MaintenanceJob::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('done', $job->status);
        $this->assertSame(10000, (int) $job->interval_km);
        $this->assertSame('available', $this->truck->fresh()->status);
        $this->assertSame(52000, (int) $this->truck->fresh()->odometer_km);
        $this->assertSame(1, AuditLog::query()->where('action', 'maintenance.created')->count());
        $this->assertSame(0, TripExpense::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, GaEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_a_lower_odometer_is_kept_on_the_job_but_does_not_wind_the_truck_back(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->periodic(['odometer_km' => 52000]));
        $this->post('/office/maintenance', $this->periodic(['category' => 'filters', 'odometer_km' => 1000, 'opened_at' => now()->subHours(2)->format('Y-m-d H:i')]));

        $this->assertSame(52000, (int) $this->truck->fresh()->odometer_km);
        $this->assertSame(1000, (int) MaintenanceJob::query()->withoutGlobalScopes()->where('category', 'filters')->value('odometer_km'));
    }

    public function test_an_open_breakdown_holds_the_truck_blocks_a_new_trip_and_shows_on_the_dashboard(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->emergency(['interval_km' => 9000, 'interval_days' => 30]))->assertSessionHas('success');

        $job = MaintenanceJob::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertNull($job->interval_km);
        $this->assertNull($job->interval_days);
        $this->assertSame('maintenance', $this->truck->fresh()->status);

        $this->get('/office')->assertOk()->assertInertia(fn (Assert $page) => $page->where('dash.fleet.counts.maint', 1));
        $this->post('/office/trips', $this->tripData())->assertSessionHas('error');
        $this->assertSame(0, Trip::query()->withoutGlobalScopes()->count());

        $this->patch("/office/maintenance/{$job->id}", $this->emergency([
            'status' => 'done', 'completed_at' => now()->format('Y-m-d H:i'), 'cost' => 450,
        ]))->assertSessionHas('success');

        $this->assertSame('available', $this->truck->fresh()->status);
        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addHours(5)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();
        $share = TripExpense::query()->withoutGlobalScopes()->where('maintenance_job_id', $job->id)->get();
        $this->assertCount(1, $share);
        $this->assertSame('company', $share[0]->paid_from);
        $this->assertEquals(450.0, (float) $share[0]->amount);
        $this->assertSame(0, GaEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_a_breakdown_during_a_running_trip_does_not_cancel_that_trip(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->emergency())->assertSessionHas('success');

        $this->assertSame('on_road', $trip->fresh()->status);
        $this->assertSame('maintenance', $this->truck->fresh()->status);
        $this->post('/office/trips', $this->tripData())->assertSessionHas('error');
    }

    public function test_the_truck_stays_in_maintenance_until_the_last_open_job_is_gone(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->emergency(['note' => 'Gearbox']));
        $this->post('/office/maintenance', $this->emergency(['note' => 'Brakes', 'category' => 'other']));
        $jobs = MaintenanceJob::query()->withoutGlobalScopes()->orderBy('id')->get();

        $this->patch("/office/maintenance/{$jobs[0]->id}", $this->emergency([
            'note' => 'Gearbox', 'status' => 'done', 'completed_at' => now()->format('Y-m-d H:i'), 'cost' => 200,
        ]))->assertSessionHas('success');
        $this->assertSame('maintenance', $this->truck->fresh()->status);

        $this->delete("/office/maintenance/{$jobs[1]->id}")->assertSessionHas('success');
        $this->assertSame('available', $this->truck->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'maintenance.deleted')->count());
    }

    public function test_a_finished_job_does_not_release_a_truck_that_was_put_in_maintenance_by_hand(): void
    {
        $this->setUpFleet();
        $this->truck->update(['status' => 'maintenance']);
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->periodic())->assertSessionHas('success');
        $this->assertSame('maintenance', $this->truck->fresh()->status);

        $job = MaintenanceJob::query()->withoutGlobalScopes()->firstOrFail();
        $this->delete("/office/maintenance/{$job->id}");
        $this->assertSame('maintenance', $this->truck->fresh()->status);
    }

    public function test_finishing_an_open_job_releases_a_truck_even_if_it_was_already_in_maintenance(): void
    {
        $this->setUpFleet();
        $this->truck->update(['status' => 'maintenance']);
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->emergency());
        $job = MaintenanceJob::query()->withoutGlobalScopes()->firstOrFail();
        $this->patch("/office/maintenance/{$job->id}", $this->emergency([
            'status' => 'done', 'completed_at' => now()->format('Y-m-d H:i'), 'cost' => 100,
        ]));

        $this->assertSame('available', $this->truck->fresh()->status);
    }

    public function test_the_truck_form_cannot_mark_it_available_while_a_job_is_open(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');
        $this->post('/office/maintenance', $this->emergency());

        $type = VehicleType::query()->withoutGlobalScopes()->where('company_id', $this->co->id)->value('id');
        $this->patch("/office/vehicles/{$this->truck->id}", [
            'plate_number' => $this->truck->plate_number, 'plate_letters' => $this->truck->plate_letters,
            'vehicle_type_id' => $type, 'ownership' => 'own', 'status' => 'available',
        ])->assertSessionHas('error');

        $this->assertSame('maintenance', $this->truck->fresh()->status);
    }

    public function test_a_finished_periodic_service_without_an_interval_or_a_breakdown_without_a_note_is_refused(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->periodic(['interval_km' => null, 'interval_days' => null]))->assertSessionHas('error');
        $this->post('/office/maintenance', $this->emergency(['note' => null]))->assertSessionHas('error');
        $this->post('/office/maintenance', $this->periodic(['interval_days' => null, 'odometer_km' => null]))->assertSessionHas('error');

        $this->assertSame(0, MaintenanceJob::query()->withoutGlobalScopes()->count());
    }

    public function test_the_page_needs_the_permission_and_another_company_cannot_see_the_job(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web')->post('/office/maintenance', $this->periodic());
        $job = MaintenanceJob::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->officeUser($this->co, ['trips.view']), 'web')->get('/office/maintenance')->assertForbidden();
        $this->actingAs($this->admin, 'web')->get('/office/maintenance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Office/Maintenance/Index')->where('totals.jobs', 1));

        $foreign = $this->companyAdmin();
        $this->actingAs($foreign, 'web')->patch("/office/maintenance/{$job->id}", $this->periodic())->assertNotFound();
        $this->post('/office/maintenance', $this->periodic())->assertSessionHasErrors('vehicle_id');
        $this->assertSame(1, MaintenanceJob::query()->withoutGlobalScopes()->count());
    }

    public function test_the_report_lists_the_cost_and_only_the_latest_schedule_is_live(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $opened = now()->startOfMonth();
        $this->post('/office/maintenance', $this->periodic([
            'opened_at' => $opened->copy()->addHours(3)->format('Y-m-d H:i'), 'completed_at' => $opened->copy()->addHours(4)->format('Y-m-d H:i'),
            'odometer_km' => 10000, 'interval_km' => 1000, 'cost' => 700,
        ]));
        $this->post('/office/maintenance', $this->periodic([
            'opened_at' => $opened->copy()->addHours(6)->format('Y-m-d H:i'), 'completed_at' => $opened->copy()->addHours(7)->format('Y-m-d H:i'),
            'odometer_km' => 10500, 'interval_km' => 10000, 'cost' => 800,
        ]));
        $this->truck->forceFill(['odometer_km' => 20500])->save();

        $this->get('/office/maintenance?tab=due')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'due')->has('due', 1)->where('due.0.state', 'overdue')->where('due.0.odometer_km', 10500));

        $this->get('/office/reports/maintenance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.key', 'maintenance')
            ->where('report.rows', function ($rows) {
                return count($rows) === 2
                    && $rows[0]['state'] === ''
                    && $rows[0]['odometer'] === 10000
                    && $rows[1]['state'] === __('reports.maintenance_state.overdue')
                    && $rows[1]['kind'] === __('reports.maintenance_kind.periodic')
                    && abs((float) $rows[0]['amount'] + (float) $rows[1]['amount'] - 1500) < 0.01
                    && (float) $rows[0]['allocated'] === 0.0 && (float) $rows[0]['remaining'] === 700.0
                    && (float) $rows[1]['allocated'] === 0.0 && (float) $rows[1]['remaining'] === 800.0;
            })
            ->where('report.totals.jobs', 2)
            ->where('report.totals.open', 0)
            ->where('report.totals.amount', fn ($v) => abs((float) $v - 1500) < 0.01)
            ->where('report.totals.allocated', fn ($v) => abs((float) $v) < 0.01)
            ->where('report.totals.remaining', fn ($v) => abs((float) $v - 1500) < 0.01)
            ->where('report.note', __('reports.maintenance_note', ['jobs' => 2, 'open' => 0])));

        $this->assertSame(0, TripExpense::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, GaEntry::query()->withoutGlobalScopes()->count());
    }

    /** @param array<string,mixed> $overrides */
    private function periodic(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $this->truck->id, 'kind' => 'periodic', 'category' => 'oil', 'status' => 'done',
            'opened_at' => now()->subHours(3)->format('Y-m-d H:i'), 'completed_at' => now()->subHour()->format('Y-m-d H:i'),
            'odometer_km' => 52000, 'cost' => 800, 'workshop' => 'El Nil', 'note' => null,
            'interval_km' => 10000, 'interval_days' => 90,
        ], $overrides);
    }

    /** @param array<string,mixed> $overrides */
    private function emergency(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $this->truck->id, 'kind' => 'emergency', 'category' => 'other', 'status' => 'open',
            'opened_at' => now()->subHour()->format('Y-m-d H:i'), 'completed_at' => null,
            'odometer_km' => null, 'cost' => null, 'workshop' => null, 'note' => 'Gearbox failed',
            'interval_km' => null, 'interval_days' => null,
        ], $overrides);
    }
}
