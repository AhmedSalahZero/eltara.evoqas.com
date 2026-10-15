<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripCrew;
use App\Models\TripExpense;
use App\Models\WalletEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: second driver / assistant on a trip, and route hours
//  Location: tests/Feature/TripCrewTest.php
//  The main driver holds all the money. A crew member is recorded with an
//  allowance; it becomes an "allowance" expense from the main driver's
//  custody when loading starts, and is added to the trip's allowance budget.
//  A route's driving hours and waiting hours make the trip's busy time.
// ══════════════════════════════════════════════════════════════════

class TripCrewTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    public function test_an_assistant_allowance_is_posted_from_custody_when_loading_starts(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData());
        $trip = Trip::query()->firstOrFail();
        $standardBefore = (float) ($trip->standard_budget[(string) $this->cats['allow']] ?? 0);

        $this->post("/office/trips/{$trip->id}/crew", ['role' => 'assistant', 'name' => 'Ahmed', 'allowance' => 300])->assertSessionHas('success');

        // Planned trip: recorded, nothing posted yet, and the allowance budget grew by his allowance.
        $crew = TripCrew::query()->firstOrFail();
        $this->assertNull($crew->trip_expense_id);
        $this->assertSame(0, TripExpense::query()->where('trip_id', $trip->id)->count());
        $this->assertEquals($standardBefore + 300, (float) $trip->fresh()->standard_budget[(string) $this->cats['allow']]);

        $url = "/office/trips/{$trip->id}";
        $this->post("{$url}/step", ['step' => 'accept'])->assertSessionHas('success');
        $this->post("{$url}/custody", ['amount' => 3000])->assertSessionHas('success');
        $this->travel(10)->seconds();
        $this->post("{$url}/step", ['step' => 'loading'])->assertSessionHas('success');

        $expense = TripExpense::query()->where('trip_id', $trip->id)->where('expense_category_id', $this->cats['allow'])->firstOrFail();
        $this->assertEquals(300.0, (float) $expense->amount);
        $this->assertSame('custody', $expense->paid_from);
        $this->assertSame($expense->id, $crew->fresh()->trip_expense_id);
        $this->assertEquals(-300.0, (float) WalletEntry::query()->where('trip_id', $trip->id)->where('wallet', 'custody')->where('type', 'expense')->sum('amount'));
    }

    public function test_changing_or_removing_a_crew_member_changes_the_expense_and_the_budget(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData());
        $trip = Trip::query()->firstOrFail();
        $url = "/office/trips/{$trip->id}";
        $this->post("{$url}/step", ['step' => 'accept']);
        $this->post("{$url}/custody", ['amount' => 3000]);
        $this->travel(10)->seconds();
        $this->post("{$url}/step", ['step' => 'loading'])->assertSessionHas('success');
        $standardBefore = (float) ($trip->fresh()->standard_budget[(string) $this->cats['allow']] ?? 0);

        // The trip has started: the allowance is posted at once.
        $this->post("{$url}/crew", ['role' => 'second_driver', 'name' => 'Mahmoud', 'allowance' => 400])->assertSessionHas('success');
        $crew = TripCrew::query()->firstOrFail();
        $this->assertEquals(400.0, (float) TripExpense::query()->findOrFail($crew->fresh()->trip_expense_id)->amount);

        $this->patch("{$url}/crew/{$crew->id}", ['role' => 'second_driver', 'name' => 'Mahmoud', 'allowance' => 500])->assertSessionHas('success');
        $this->assertEquals(500.0, (float) TripExpense::query()->findOrFail($crew->fresh()->trip_expense_id)->amount);
        $this->assertEquals($standardBefore + 500, (float) $trip->fresh()->standard_budget[(string) $this->cats['allow']]);

        $this->delete("{$url}/crew/{$crew->id}")->assertSessionHas('success');
        $this->assertSame(0, TripCrew::query()->count());
        $this->assertSame(0, TripExpense::query()->where('trip_id', $trip->id)->where('expense_category_id', $this->cats['allow'])->count());
        $this->assertEquals($standardBefore, (float) $trip->fresh()->standard_budget[(string) $this->cats['allow']]);
        $this->assertEquals(0.0, (float) WalletEntry::query()->where('trip_id', $trip->id)->where('wallet', 'custody')->where('type', 'like', 'expense%')->sum('amount'));
    }

    public function test_an_allowance_needs_the_permission_to_add_expenses_and_a_crew_has_a_limit(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData());
        $trip = Trip::query()->firstOrFail();
        $url = "/office/trips/{$trip->id}";

        $clerk = $this->officeUser($this->co, ['trips.view', 'trips.edit']);
        $this->actingAs($clerk, 'web')->post("{$url}/crew", ['role' => 'assistant', 'name' => 'Ali', 'allowance' => 200])->assertSessionHas('error');
        $this->post("{$url}/crew", ['role' => 'assistant', 'name' => 'Ali'])->assertSessionHas('success');

        $this->actingAs($this->admin, 'web');
        foreach (['B', 'C', 'D'] as $i => $name) {
            $this->travel(10)->seconds();
            $this->post("{$url}/crew", ['role' => 'assistant', 'name' => $name])->assertSessionHas('success');
        }
        $this->travel(10)->seconds();
        $this->post("{$url}/crew", ['role' => 'assistant', 'name' => 'E'])->assertSessionHas('error');
        $this->assertSame(4, TripCrew::query()->count());
    }

    public function test_the_trip_takes_driving_hours_and_waiting_hours_from_its_route(): void
    {
        $this->setUpFleet();
        $this->route->update(['usual_hours' => 10, 'wait_hours' => 4]);

        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData())->assertSessionHasNoErrors();

        $trip = Trip::query()->firstOrFail();
        $this->assertEquals(10.0, (float) $trip->road_hours);      // the client's expected arrival uses half of this
        $this->assertEquals(14.0, (float) $trip->planned_hours);   // how long the truck is busy
    }
}
