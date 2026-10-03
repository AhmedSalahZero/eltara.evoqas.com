<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: reports and exports (Step 7, Scope §6.14)
//  Location: tests/Feature/ReportsTest.php
//  Feature doc: docs/STEP_07_DASHBOARD_REPORTS_NOTIFICATIONS_AUDIT.md
//  12 reports; each shows on screen, exports Excel and prints as PDF
//  from the same numbers, filtered by period / customer / truck /
//  driver. Permission: reports.view.
// ══════════════════════════════════════════════════════════════════

class ReportsTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('trip_files');
    }

    private function near(float $expected): \Closure
    {
        return fn ($v) => abs((float) $v - $expected) < 0.01;
    }

    private function deliveredTrip(): Trip
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 3400]);
        $this->post("/office/trips/{$trip->id}/deliver", ['pod' => $this->pod(), 'receiver' => 'Store keeper'])->assertSessionHas('success');

        return $trip->fresh();
    }

    public function test_the_hub_lists_the_twelve_reports_and_needs_the_permission(): void
    {
        $this->setUpFleet();

        $this->actingAs($this->admin, 'web')->get('/office/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Reports/Index')->has('items', 12)->where('items.0.key', 'trips'));

        $user = $this->officeUser($this->co, ['trips.view']);
        $this->actingAs($user, 'web')->get('/office/reports')->assertForbidden();
        $this->get('/office/reports/trips/export')->assertForbidden();
    }

    public function test_the_trip_profitability_report_shows_revenue_cost_profit_and_totals(): void
    {
        $trip = $this->deliveredTrip();

        $this->get('/office/reports/trips')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Reports/Show')
            ->where('report.key', 'trips')
            ->where('report.rows.0.number', $trip->number)
            ->where('report.rows.0.revenue', $this->near(10200))
            ->where('report.rows.0.cost', $this->near(3400))
            ->where('report.rows.0.profit', $this->near(6800))
            ->where('report.totals.profit', $this->near(6800)));
    }

    public function test_the_grouped_reports_add_up_to_the_same_profit(): void
    {
        $this->deliveredTrip();

        foreach (['customers', 'vehicles', 'drivers', 'routes'] as $key) {
            $this->get("/office/reports/{$key}")->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('report.rows', 1)->where('report.rows.0.trips', 1)->where('report.totals.profit', $this->near(6800)));
        }
    }

    public function test_filters_narrow_the_report(): void
    {
        $this->deliveredTrip();
        $other = Customer::factory()->for($this->co)->create();

        $this->get('/office/reports/trips?customer_id='.$other->id)->assertInertia(fn (Assert $page) => $page->has('report.rows', 0));
        $this->get('/office/reports/trips?customer_id='.$this->customer->id)->assertInertia(fn (Assert $page) => $page->has('report.rows', 1));
        $last = now()->subMonthsNoOverflow(2);
        $this->get('/office/reports/trips?from='.$last->startOfMonth()->toDateString().'&to='.$last->endOfMonth()->toDateString())
            ->assertInertia(fn (Assert $page) => $page->has('report.rows', 0));
    }

    public function test_the_budget_report_compares_the_standard_with_what_was_spent(): void
    {
        $this->deliveredTrip();

        $this->get('/office/reports/budget')->assertInertia(fn (Assert $page) => $page
            ->where('report.rows', function ($rows) {
                $fuel = collect($rows)->firstWhere('actual', 3400.0) ?? collect($rows)->first(fn ($r) => abs($r['actual'] - 3400) < 0.01);

                return $fuel !== null && abs($fuel['standard'] - 3450) < 0.01 && $fuel['flag'] === '';
            }));
    }

    public function test_the_documents_report_lists_expired_and_ending_papers(): void
    {
        $this->setUpFleet();
        $this->truck->forceFill(['licence_expires_at' => today()->addDays(10), 'insurance_expires_at' => today()->subDays(2)])->save();

        $this->actingAs($this->admin, 'web')->get('/office/reports/documents?to='.today()->toDateString())->assertInertia(fn (Assert $page) => $page
            ->has('report.rows', 2)->where('report.rows.0.days', -2)->where('report.rows.1.days', 10));
    }

    public function test_excel_and_pdf_exports_work_and_are_written_to_the_audit_log(): void
    {
        $this->deliveredTrip();
        $this->admin->forceFill(['language' => 'en'])->save(); // this test reads the English wording

        $xlsx = $this->get('/office/reports/trips/export');
        $xlsx->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', (string) $xlsx->headers->get('content-disposition'));

        $this->get('/office/reports/trips/print')->assertOk()->assertSee('10,200')->assertSee('Print / Save as PDF');

        $this->assertSame(2, AuditLog::query()->where('action', 'report.exported')->count());
    }

    public function test_an_unknown_report_is_not_found(): void
    {
        $this->setUpFleet();

        $this->actingAs($this->admin, 'web')->get('/office/reports/everything')->assertNotFound();
    }

    public function test_every_report_opens_on_an_empty_company(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        foreach (\App\Services\Reports\ReportService::KEYS as $key) {
            $this->get("/office/reports/{$key}")->assertOk();
            $this->get("/office/reports/{$key}/print")->assertOk();
            $this->get("/office/reports/{$key}/export")->assertOk();
        }
    }
}
