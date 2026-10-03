<?php

namespace Tests\Feature;

use App\Models\GaEntry;
use App\Models\MonthClose;
use App\Models\Trip;
use App\Models\TripAllocation;
use App\Services\Trips\TripService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: month close and true profit (Step 6, Scope §6.13, §10)
//  Location: tests/Feature/MonthCloseTest.php
//  Feature doc: docs/STEP_06_FUEL_FINANCE_MONTH_CLOSE.md
//  Rate = month's G&A ÷ own-fleet km. Closing locks the month and
//  saves each trip's share; re-opening needs a reason.
// ══════════════════════════════════════════════════════════════════

class MonthCloseTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    private function lastMonth(): string
    {
        return now()->subMonthNoOverflow()->format('Y-m');
    }

    /** A 400 km trip that ran entirely inside last month (state set directly). */
    private function lastMonthTrip(): Trip
    {
        $trip = Tenant::forCompany($this->co->id, fn () => app(TripService::class)->create($this->tripData(), $this->admin));
        $start = now()->subMonthNoOverflow()->startOfMonth()->addDays(9)->setTime(6, 0);
        $trip->forceFill([
            'status' => 'delivered', 'km' => 400, 'departed_at' => $start, 'loading_started_at' => $start->copy()->subHours(2),
            'delivered_at' => $start->copy()->addDays(2), 'is_hired' => false,
        ])->save();

        return $trip->fresh();
    }

    private function addLine(string $month, string $code, float $amount): void
    {
        $this->post('/office/close/lines', ['month' => $month, 'code' => $code, 'amount' => $amount])->assertSessionHas('success');
    }

    public function test_closing_saves_the_rate_and_each_trips_share_and_locks_the_month(): void
    {
        $this->setUpFleet();
        $trip = $this->lastMonthTrip();
        $this->actingAs($this->admin, 'web');
        $this->addLine($this->lastMonth(), 'rent', 3000);
        $this->addLine($this->lastMonth(), 'insurance', 1000);          // G&A 4,000 ÷ 400 km = 10 per km

        $this->post('/office/close/run', ['month' => $this->lastMonth()])->assertSessionHas('success');

        $close = MonthClose::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertTrue($close->isClosed());
        $this->assertEquals(4000.0, (float) $close->ga_total);
        $this->assertEquals(10.0, (float) $close->rate);

        $allocation = TripAllocation::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->firstOrFail();
        $this->assertEquals(400.0, (float) $allocation->km);
        $this->assertEquals(4000.0, (float) $allocation->ga_share);

        // The month is locked: its G&A lines cannot change.
        $this->post('/office/close/lines', ['month' => $this->lastMonth(), 'code' => 'tyres', 'amount' => 500])->assertSessionHas('error');
        $this->assertSame(2, GaEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_a_month_that_has_not_ended_cannot_be_closed(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');
        $this->addLine(now()->format('Y-m'), 'rent', 1000);

        $this->post('/office/close/run', ['month' => now()->format('Y-m')])->assertSessionHas('error');

        $this->assertSame(0, MonthClose::query()->withoutGlobalScopes()->count());
    }

    public function test_a_month_without_ga_or_without_km_cannot_be_closed(): void
    {
        $this->setUpFleet();
        $this->lastMonthTrip();
        $this->actingAs($this->admin, 'web');

        $this->post('/office/close/run', ['month' => $this->lastMonth()])->assertSessionHas('error');      // no G&A lines
        $this->assertSame(0, MonthClose::query()->withoutGlobalScopes()->count());

        $twoMonthsAgo = now()->subMonthsNoOverflow(2)->format('Y-m');
        $this->addLine($twoMonthsAgo, 'rent', 1000);
        $this->post('/office/close/run', ['month' => $twoMonthsAgo])->assertSessionHas('error');           // no km that month
        $this->assertSame(0, MonthClose::query()->withoutGlobalScopes()->count());
    }

    public function test_a_closed_month_cannot_be_closed_again(): void
    {
        $this->setUpFleet();
        $this->lastMonthTrip();
        $this->actingAs($this->admin, 'web');
        $this->addLine($this->lastMonth(), 'rent', 4000);
        $this->post('/office/close/run', ['month' => $this->lastMonth()])->assertSessionHas('success');

        $this->post('/office/close/run', ['month' => $this->lastMonth()])->assertSessionHas('error');

        $this->assertSame(1, MonthClose::query()->withoutGlobalScopes()->count());
    }

    public function test_reopening_needs_a_reason_and_unlocks_the_month(): void
    {
        $this->setUpFleet();
        $this->lastMonthTrip();
        $this->actingAs($this->admin, 'web');
        $this->addLine($this->lastMonth(), 'rent', 4000);
        $this->post('/office/close/run', ['month' => $this->lastMonth()]);

        $this->post('/office/close/reopen', ['month' => $this->lastMonth()])->assertSessionHasErrors('reason');
        $this->assertTrue(MonthClose::query()->withoutGlobalScopes()->firstOrFail()->isClosed());

        $this->post('/office/close/reopen', ['month' => $this->lastMonth(), 'reason' => 'Insurance invoice was missing'])->assertSessionHas('success');

        $close = MonthClose::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertFalse($close->isClosed());
        $this->assertSame('Insurance invoice was missing', $close->reopen_reason);
        $this->post('/office/close/lines', ['month' => $this->lastMonth(), 'code' => 'tyres', 'amount' => 500])->assertSessionHas('success');
    }

    public function test_closing_and_reopening_are_separate_permissions(): void
    {
        $this->setUpFleet();
        $accountant = $this->officeUser($this->co, ['month_close.view', 'month_close.create', 'month_close.approve']);

        $this->actingAs($accountant, 'web')->get('/office/close')->assertOk();
        $this->post('/office/close/reopen', ['month' => $this->lastMonth(), 'reason' => 'x'])->assertForbidden();

        $viewer = $this->officeUser($this->co, ['month_close.view']);
        $this->actingAs($viewer, 'web')->post('/office/close/run', ['month' => $this->lastMonth()])->assertForbidden();
        $this->post('/office/close/lines', ['month' => $this->lastMonth(), 'code' => 'rent', 'amount' => 1])->assertForbidden();
    }

    public function test_the_trip_page_shows_a_final_true_profit_once_its_month_is_closed(): void
    {
        $this->setUpFleet();
        $trip = $this->lastMonthTrip();
        $trip->forceFill(['status' => 'settled', 'settled_at' => now()->subDays(1)])->save();
        $this->actingAs($this->admin, 'web');
        $this->addLine($this->lastMonth(), 'rent', 4000);

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn ($page) => $page->where('figures.true_estimate', true));

        $this->post('/office/close/run', ['month' => $this->lastMonth()])->assertSessionHas('success');

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn ($page) => $page
            ->where('figures.true_estimate', false)
            ->where('figures.ga_share', 4000));
    }
}
