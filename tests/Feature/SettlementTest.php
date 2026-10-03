<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\TripSettlement;
use App\Models\WalletEntry;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: trip settlement (Step 3, Scope §6.5, §10)
//  Location: tests/Feature/SettlementTest.php
//  Feature doc: docs/STEP_03_TRIPS_AND_WALLETS.md §6
// ══════════════════════════════════════════════════════════════════

class SettlementTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('trip_files');
    }

    private function deliver(Trip $trip): void
    {
        $this->post("/office/trips/{$trip->id}/deliver", ['pod' => $this->pod(), 'receiver' => 'Store keeper'])->assertSessionHas('success');
    }

    public function test_a_trip_cannot_be_settled_before_delivery_with_its_photo(): void
    {
        $trip = $this->runningTrip();

        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/settle")->assertSessionHas('error');
        $this->assertSame('on_road', $trip->fresh()->status);
    }

    public function test_settling_hands_over_the_net_and_closes_each_wallet(): void
    {
        // Custody 3,000 − fuel 1,200 + 500 moved in = 2,300 back · collections 2,000 − 500 = 1,500 in
        // · own pocket 260 refunded → NET = 2,300 + 1,500 − 260 = 3,540 (Scope §10)
        $trip = $this->runningTrip('limit', 1000);
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 1200]);
        $this->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['toll'], 'paid_from' => 'own_pocket', 'amount' => 260]);
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);
        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 500, 'reason' => 'Diesel']);
        $this->deliver($trip);

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $page) => $page
            ->where('settlement.custody', 2300)       // 3,000 − 1,200 + 500 moved in
            ->where('settlement.collections', 1500)
            ->where('settlement.pocket', 260)
            ->where('settlement.net', 3540)
            ->where('settlement.can_settle', true)
            // The client has not confirmed the 2,000 yet: a warning, not a block.
            ->where('settlement.warnings.0.code', 'awaiting_client'));

        $this->post("/office/trips/{$trip->id}/settle", ['note' => 'Paid at the safe'])->assertSessionHas('error'); // the client's 2,000 is unconfirmed: it must be accepted on purpose
        $this->post("/office/trips/{$trip->id}/settle", ['note' => 'Paid at the safe', 'accept_unconfirmed' => 1])->assertSessionHas('success');

        $trip->refresh();
        $this->assertSame('settled', $trip->status);
        $this->assertNotNull($trip->settled_at);
        $settlement = TripSettlement::query()->firstOrFail();
        $this->assertEquals(3540, $settlement->net_amount);
        $this->assertEquals(2000, $settlement->unconfirmed_amount);

        // One entry per wallet, and every trip wallet is back to zero.
        $types = WalletEntry::query()->where('source_type', 'TripSettlement')->pluck('type')->sort()->values()->all();
        $this->assertSame(['collections_handed_in', 'custody_returned', 'pocket_refunded'], $types);
        $this->assertEquals(['custody' => 0.0, 'collections' => 0.0, 'advances' => 0.0, 'pocket' => 0.0], $this->balances($trip));
        $this->assertTrue(AuditLog::query()->where('action', 'trip.settled')->exists());
    }

    public function test_a_waiting_transfer_or_an_open_dispute_blocks_settlement(): void
    {
        $trip = $this->runningTrip('approval');
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);
        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 300, 'reason' => 'Diesel']);
        $this->deliver($trip);

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $page) => $page
            ->where('settlement.can_settle', false)->where('settlement.blockers.0.code', 'pending_transfers'));
        $this->post("/office/trips/{$trip->id}/settle")->assertSessionHas('error');

        $this->post('/office/transfers/'.\App\Models\WalletTransfer::query()->value('id').'/approve');
        $this->travel(10)->seconds();

        $collection = TripCollection::query()->firstOrFail();
        Tenant::forCompany($this->co->id, fn () => app(CollectionService::class)->dispute($collection, 'client', Actor::system()));
        $this->post("/office/trips/{$trip->id}/settle")->assertSessionHas('error');

        $this->post("/office/trips/{$trip->id}/collections/{$collection->id}/resolve", ['resolution' => 'accepted']);
        $this->travel(10)->seconds(); // the same form twice within seconds is ignored as a double-click
        $this->post("/office/trips/{$trip->id}/settle")->assertSessionHas('success');
        $this->assertSame('settled', $trip->fresh()->status);
    }

    public function test_when_the_driver_spent_more_than_his_custody_the_company_pays_him(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 3400]);
        $this->deliver($trip);
        $this->post("/office/trips/{$trip->id}/settle")->assertSessionHas('success');

        $settlement = TripSettlement::query()->firstOrFail();
        $this->assertEquals(-400, $settlement->custody_balance);
        $this->assertEquals(-400, $settlement->net_amount);
        $this->assertSame('custody_refunded', WalletEntry::query()->where('source_type', 'TripSettlement')->value('type'));
        $this->assertEquals(0, $this->balances($trip)['custody']);
    }

    public function test_nothing_on_a_settled_trip_can_change(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');
        $this->deliver($trip);
        $this->post("/office/trips/{$trip->id}/settle")->assertSessionHas('success');

        $this->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 100])->assertSessionHas('error');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 100])->assertSessionHas('error');
        $this->travel(10)->seconds();
        $this->post("/office/trips/{$trip->id}/settle")->assertSessionHas('error');
        $this->patch("/office/trips/{$trip->id}", ['weight_tons' => 30])->assertSessionHas('error');
        $this->assertSame(1, TripSettlement::query()->count());
    }

    public function test_settling_needs_the_settlement_permission(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');
        $this->deliver($trip);

        $clerk = $this->officeUser($this->co, ['trips.view', 'trips.edit', 'trip_settlement.view']);
        $this->actingAs($clerk, 'web')->post("/office/trips/{$trip->id}/settle")->assertForbidden();

        $cfo = $this->officeUser($this->co, ['trips.view', 'trip_settlement.view', 'trip_settlement.approve']);
        $this->actingAs($cfo, 'web')->post("/office/trips/{$trip->id}/settle")->assertSessionHas('success');
        $this->assertEquals($cfo->id, $trip->fresh()->settled_by);
    }

    public function test_delivered_trips_are_listed_for_settlement_with_what_the_driver_hands_over(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');
        $this->deliver($trip);

        $this->get('/office/wallets?tab=settle')->assertInertia(fn (Assert $page) => $page
            ->where('tiles.settle', 1)
            ->where('settle.0.number', $trip->number)
            ->where('settle.0.net', 3000)
            ->where('settle.0.blocked', false));
    }
}
