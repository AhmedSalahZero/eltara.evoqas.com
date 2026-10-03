<?php

namespace Tests\Feature;

use App\Models\FuelEntry;
use App\Models\TripExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the fuel log (Step 6, Scope §6.10)
//  Location: tests/Feature/FuelTest.php
//  Feature doc: docs/STEP_06_FUEL_FINANCE_MONTH_CLOSE.md
//  A refuel on a trip is the SAME money as the trip's fuel expense —
//  counted once. A refuel with no trip is fuel data only.
// ══════════════════════════════════════════════════════════════════

class FuelTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    public function test_a_refuel_without_a_trip_is_fuel_data_only(): void
    {
        $this->setUpFleet();

        $this->actingAs($this->admin, 'web')->post('/office/fuel', [
            'vehicle_id' => $this->truck->id, 'filled_at' => now()->subHour()->format('Y-m-d H:i'),
            'litres' => 100, 'price_per_litre' => 13.5, 'odometer_km' => 52000, 'paid_by' => 'card',
        ])->assertSessionHas('success');

        $entry = FuelEntry::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertEquals(1350.00, (float) $entry->amount);          // litres × price
        $this->assertNull($entry->trip_expense_id);
        $this->assertSame(0, TripExpense::query()->withoutGlobalScopes()->count());
        $this->assertSame(52000, (int) $this->truck->fresh()->odometer_km);
    }

    public function test_the_litres_are_worked_out_from_the_amount_when_missing(): void
    {
        $this->setUpFleet();

        $this->actingAs($this->admin, 'web')->post('/office/fuel', [
            'vehicle_id' => $this->truck->id, 'filled_at' => now()->subHour()->format('Y-m-d H:i'),
            'amount' => 1350, 'price_per_litre' => 13.5, 'paid_by' => 'card',
        ])->assertSessionHas('success');

        $this->assertEquals(100.0, (float) FuelEntry::query()->withoutGlobalScopes()->firstOrFail()->litres);
    }

    public function test_litres_or_amount_must_be_given(): void
    {
        $this->setUpFleet();

        $this->actingAs($this->admin, 'web')->post('/office/fuel', [
            'vehicle_id' => $this->truck->id, 'filled_at' => now()->subHour()->format('Y-m-d H:i'), 'paid_by' => 'card',
        ])->assertSessionHas('error');

        $this->assertSame(0, FuelEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_paying_from_custody_needs_a_trip(): void
    {
        $this->setUpFleet();

        $this->actingAs($this->admin, 'web')->post('/office/fuel', [
            'vehicle_id' => $this->truck->id, 'filled_at' => now()->subHour()->format('Y-m-d H:i'), 'litres' => 50, 'amount' => 675, 'paid_by' => 'custody',
        ])->assertSessionHas('error');

        $this->assertSame(0, FuelEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_a_refuel_on_a_trip_creates_the_trip_fuel_expense_once(): void
    {
        $trip = $this->runningTrip();

        $this->actingAs($this->admin, 'web')->post('/office/fuel', [
            'trip_id' => $trip->id, 'filled_at' => now()->subHour()->format('Y-m-d H:i'), 'litres' => 90, 'amount' => 1215, 'paid_by' => 'custody', 'odometer_km' => 60450,
        ])->assertSessionHas('success');

        $expenses = TripExpense::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->get();
        $this->assertCount(1, $expenses);
        $this->assertEquals(1215.0, (float) $expenses[0]->amount);
        $this->assertSame('custody', $expenses[0]->paid_from);

        $entry = FuelEntry::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($expenses[0]->id, (int) $entry->trip_expense_id);
        $this->assertSame($trip->vehicle_id, $entry->vehicle_id);
    }

    public function test_litres_can_be_added_to_an_expense_the_driver_already_recorded_without_counting_it_twice(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 1200])
            ->assertSessionHas('success');
        $expense = TripExpense::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->firstOrFail();

        $this->post('/office/fuel', ['trip_expense_id' => $expense->id, 'filled_at' => now()->subHour()->format('Y-m-d H:i'), 'litres' => 88.5, 'odometer_km' => 70100, 'paid_by' => 'custody'])->assertSessionHas('success');

        $this->assertSame(1, TripExpense::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->count());
        $entry = FuelEntry::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($expense->id, (int) $entry->trip_expense_id);
        $this->assertEquals(1200.0, (float) $entry->amount);

        // The same expense cannot be given litres twice.
        $this->post('/office/fuel', ['trip_expense_id' => $expense->id, 'filled_at' => now()->subHour()->format('Y-m-d H:i'), 'litres' => 10, 'paid_by' => 'custody'])->assertSessionHas('error');
        $this->assertSame(1, FuelEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_deleting_a_refuel_keeps_the_trip_expense(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post('/office/fuel', ['trip_id' => $trip->id, 'litres' => 90, 'amount' => 1215, 'paid_by' => 'card', 'filled_at' => now()->subHour()->format('Y-m-d H:i')]);
        $entry = FuelEntry::query()->withoutGlobalScopes()->firstOrFail();

        $this->delete("/office/fuel/{$entry->id}")->assertSessionHas('success');

        $this->assertSame(0, FuelEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, TripExpense::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->count());
    }

    public function test_the_screen_flags_a_truck_that_drinks_more_than_its_standard(): void
    {
        $this->setUpFleet();
        $this->truck->update(['std_km_per_litre' => 3.0]);
        $this->actingAs($this->admin, 'web');

        // 1,000 km on 450 litres = 2.2 km/L — more than 7% under 3.0.
        // Both fills are in last month, so the test gives the same answer on any day of the month.
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
        foreach ([[5, 50000, 100], [10, 51000, 450]] as [$day, $odo, $litres]) {
            $this->post('/office/fuel', ['vehicle_id' => $this->truck->id, 'filled_at' => $lastMonth->copy()->addDays($day)->format('Y-m-d H:i'), 'litres' => $litres, 'price_per_litre' => 13.5, 'odometer_km' => $odo, 'paid_by' => 'card'])
                ->assertSessionHas('success');
        }

        $this->get('/office/fuel?tab=trucks&month='.now()->subMonthNoOverflow()->format('Y-m'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Fuel/Index')
            ->where('totals.fills', 2)
            ->where('totals.flagged', 1)
            ->where('trucks.0.flagged', true));
    }

    public function test_a_user_without_the_permission_cannot_open_or_change_fuel(): void
    {
        $this->setUpFleet();
        $nobody = $this->officeUser($this->co, ['trips.view']);

        $this->actingAs($nobody, 'web')->get('/office/fuel')->assertForbidden();
        $this->post('/office/fuel', ['vehicle_id' => $this->truck->id, 'litres' => 10, 'paid_by' => 'card'])->assertForbidden();
    }
}
