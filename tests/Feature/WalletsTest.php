<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DriverAdvance;
use App\Models\TripCollection;
use App\Models\TripExpense;
use App\Models\WalletEntry;
use App\Models\WalletTransfer;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the three wallets, transfers and approvals
//  (Step 3, Scope §6.4, §9, §10)
//  Location: tests/Feature/WalletsTest.php
//  Feature doc: docs/STEP_03_TRIPS_AND_WALLETS.md §3–§5
// ══════════════════════════════════════════════════════════════════

class WalletsTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    private function expense(int $tripId, array $data)
    {
        return $this->post("/office/trips/{$tripId}/expenses", array_merge(['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody'], $data));
    }

    public function test_the_custody_balance_follows_the_scope_formula(): void
    {
        // Custody = issued + transfers in − spent − moved to advances − returned (Scope §10)
        $trip = $this->runningTrip('limit', 1000);
        $this->actingAs($this->admin, 'web');

        $this->expense($trip->id, ['amount' => 1200])->assertSessionHas('success');
        $this->expense($trip->id, ['is_personal' => true, 'expense_category_id' => null, 'amount' => 300, 'note' => 'Lunch money'])->assertSessionHas('success');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 2000])->assertSessionHas('success');
        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 500, 'reason' => 'Diesel'])->assertSessionHas('success');

        $b = $this->balances($trip);
        $this->assertEquals(3000 - 1200 - 300 + 500, $b['custody']);   // 2,000
        $this->assertEquals(2000 - 500, $b['collections']);            // 1,500
        $this->assertEquals(300, $b['advances']);

        // Personal spending is an advance, not a trip cost.
        $advance = DriverAdvance::query()->firstOrFail();
        $this->assertSame('trip_personal', $advance->source);
        $this->assertEquals(300, $advance->amount);

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $page) => $page
            ->where('figures.cost', 1200)
            ->where('wallets.custody.spent', 1200)
            ->where('wallets.custody.to_advances', 300)
            ->where('wallets.custody.transfers_in', 500)
            ->where('wallets.collections.transferred_out', 500)
            ->where('available', 1500));
    }

    public function test_personal_spending_stays_in_custody_when_the_setting_is_off(): void
    {
        $trip = $this->runningTrip('limit', 1000, ['personal_spend_to_advance' => false]);
        $this->actingAs($this->admin, 'web');

        $this->expense($trip->id, ['is_personal' => true, 'expense_category_id' => null, 'amount' => 300])->assertSessionHas('success');

        // The driver hands it back at settlement instead.
        $this->assertEquals(3000, $this->balances($trip)['custody']);
        $this->assertEquals(0, $this->balances($trip)['advances']);
        $this->assertSame(0, DriverAdvance::query()->count());
    }

    public function test_policy_approval_always_waits_and_nothing_moves_until_approved(): void
    {
        $trip = $this->runningTrip('approval');
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);

        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 200, 'reason' => 'Tolls'])->assertSessionHas('success');
        $transfer = WalletTransfer::query()->firstOrFail();
        $this->assertSame('pending', $transfer->status);
        $this->assertEquals(3000, $this->balances($trip)['custody']);
        $this->assertEquals(2000, $this->balances($trip)['collections']);

        $this->post("/office/transfers/{$transfer->id}/approve")->assertSessionHas('success');
        $this->assertSame('approved', $transfer->fresh()->status);
        $this->assertEquals($this->admin->id, $transfer->fresh()->decided_by);
        $this->assertEquals(3200, $this->balances($trip)['custody']);
        $this->assertEquals(1800, $this->balances($trip)['collections']);
        $this->assertTrue(AuditLog::query()->where('action', 'transfer.approved')->exists());
    }

    public function test_policy_limit_is_automatic_up_to_the_limit_and_listed_for_review(): void
    {
        $trip = $this->runningTrip('limit', 1000);
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 5000]);

        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 800, 'reason' => 'Night stop']);
        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 1500, 'reason' => 'Extra diesel']);

        [$small, $big] = WalletTransfer::query()->orderBy('id')->get()->all();
        $this->assertSame('auto', $small->status);
        $this->assertSame('pending', $big->status);
        $this->assertEquals(3800, $this->balances($trip)['custody']);

        $this->get('/office/wallets?tab=review')->assertInertia(fn (Assert $page) => $page
            ->component('Office/Wallets/Index')
            ->where('tiles.review', 1)->where('tiles.pending', 1)
            ->where('review.0.id', $small->id));

        $this->post("/office/transfers/{$small->id}/review")->assertSessionHas('success');
        $this->assertNotNull($small->fresh()->reviewed_at);
        $this->get('/office/wallets?tab=review')->assertInertia(fn (Assert $page) => $page->where('tiles.review', 0));
    }

    public function test_policy_auto_needs_no_approval_at_any_amount(): void
    {
        $trip = $this->runningTrip('auto');
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 9000]);

        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 8000, 'reason' => 'Long trip'])->assertSessionHas('success');
        $this->assertSame('auto', WalletTransfer::query()->value('status'));
    }

    public function test_the_policy_can_be_changed_for_one_trip(): void
    {
        $trip = $this->runningTrip('limit');
        $this->actingAs($this->admin, 'web');

        $this->put("/office/trips/{$trip->id}/policy", ['transfer_policy' => 'auto'])->assertSessionHas('success');
        $this->assertSame('auto', $trip->fresh()->transfer_policy);
        $this->assertTrue(AuditLog::query()->where('action', 'trip.policy_changed')->exists());
    }

    public function test_approving_respects_each_persons_approval_limit(): void
    {
        $trip = $this->runningTrip('approval');
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 5000]);
        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 1800, 'reason' => 'Diesel']);
        $transfer = WalletTransfer::query()->firstOrFail();

        $manager = $this->officeUser($this->co, ['wallet_transfers.view', 'wallet_transfers.approve'], ['approval_limit' => 1000]);
        $this->actingAs($manager, 'web')->post("/office/transfers/{$transfer->id}/approve")->assertSessionHas('error');
        $this->assertSame('pending', $transfer->fresh()->status);

        $this->get('/office/wallets')->assertInertia(fn (Assert $page) => $page->where('pending.0.can_approve', false)->where('tiles.limit', 1000));

        // Above the limit → the company admin.
        $this->actingAs($this->admin, 'web')->post("/office/transfers/{$transfer->id}/approve")->assertSessionHas('success');
        $this->assertSame('approved', $transfer->fresh()->status);

        // Without the permission at all → refused at the door.
        $this->actingAs($this->officeUser($this->co, ['wallet_transfers.view']), 'web')
            ->post("/office/transfers/{$transfer->id}/reject", ['note' => 'x'])->assertForbidden();
    }

    public function test_a_rejected_transfer_moves_nothing_and_keeps_the_reason(): void
    {
        $trip = $this->runningTrip('approval');
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);
        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 700, 'reason' => 'Diesel']);
        $transfer = WalletTransfer::query()->firstOrFail();

        $this->post("/office/transfers/{$transfer->id}/reject", [])->assertSessionHasErrors('note');
        $this->post("/office/transfers/{$transfer->id}/reject", ['note' => 'Use the custody'])->assertSessionHas('success');

        $this->assertSame('rejected', $transfer->fresh()->status);
        $this->assertSame('Use the custody', $transfer->fresh()->decision_note);
        $this->assertEquals(2000, $this->balances($trip)['collections']);
    }

    public function test_a_transfer_cannot_exceed_the_collection_money_held(): void
    {
        $trip = $this->runningTrip('limit');
        $this->actingAs($this->admin, 'web');

        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 100, 'reason' => 'x'])->assertSessionHas('error');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 1000]);
        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 1200, 'reason' => 'x'])->assertSessionHas('error');
        $this->assertSame(0, WalletTransfer::query()->count());
    }

    public function test_an_expense_paid_from_collections_records_a_transfer_and_deleting_it_undoes_everything(): void
    {
        $trip = $this->runningTrip('approval');
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);

        $this->expense($trip->id, ['expense_category_id' => $this->cats['repair'], 'paid_from' => 'collections', 'amount' => 450])->assertSessionHas('success');
        $expense = TripExpense::query()->firstOrFail();
        $transfer = WalletTransfer::query()->firstOrFail();
        $this->assertEquals($transfer->id, $expense->wallet_transfer_id);
        $this->assertSame('pending', $transfer->status);                   // the trip's policy: approval
        $this->assertEquals(3000 - 450, $this->balances($trip)['custody']); // the money was spent

        $this->post("/office/transfers/{$transfer->id}/approve");
        $this->assertEquals(3000, $this->balances($trip)['custody']);
        $this->assertEquals(1550, $this->balances($trip)['collections']);

        $this->delete("/office/trips/{$trip->id}/expenses/{$expense->id}")->assertSessionHas('success');
        $this->assertSame('cancelled', $transfer->fresh()->status);
        $this->assertEquals(3000, $this->balances($trip)['custody']);
        $this->assertEquals(2000, $this->balances($trip)['collections']);
        $this->assertTrue(AuditLog::query()->where('action', 'expense.deleted')->exists());
    }

    public function test_correcting_an_expense_adds_a_correction_and_never_rewrites_the_ledger(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');
        $this->expense($trip->id, ['amount' => 1200]);
        $expense = TripExpense::query()->firstOrFail();

        $this->post("/office/trips/{$trip->id}/expenses/{$expense->id}", [
            'expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 1000,
        ])->assertSessionHas('success');

        $this->assertEquals(2000, $this->balances($trip)['custody']);
        $rows = WalletEntry::query()->withoutGlobalScopes()->where('source_type', 'TripExpense')->orderBy('id')->get();
        $this->assertSame(['expense', 'expense_correction'], $rows->pluck('type')->all());
        $this->assertEquals([-1200, 200], $rows->pluck('amount')->all());

        $this->expectException(\LogicException::class);
        $rows->first()->update(['amount' => 0]);
    }

    public function test_own_pocket_spending_is_owed_to_the_driver(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web');

        $this->expense($trip->id, ['expense_category_id' => $this->cats['toll'], 'paid_from' => 'own_pocket', 'amount' => 260])->assertSessionHas('success');
        $this->expense($trip->id, ['is_personal' => true, 'expense_category_id' => null, 'paid_from' => 'own_pocket', 'amount' => 50])->assertSessionHas('error');

        $b = $this->balances($trip);
        $this->assertEquals(3000, $b['custody']);
        $this->assertEquals(260, $b['pocket']);
    }

    public function test_disputed_cash_does_not_count_until_management_resolves_it(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 1500]);
        $collection = TripCollection::query()->firstOrFail();
        $this->assertSame('awaiting_client', $collection->state());
        $this->assertEquals(1500, $this->balances($trip)['collections']);

        // The client disputes it (from the portal in Step 5).
        Tenant::forCompany($this->co->id, fn () => app(CollectionService::class)->dispute($collection, 'client', Actor::system(), 'We paid 1,000'));
        $this->assertEquals(0, $this->balances($trip)['collections']);

        $this->post("/office/trips/{$trip->id}/collections/{$collection->id}/resolve", ['resolution' => 'accepted'])->assertSessionHas('success');
        $this->assertSame('confirmed', $collection->fresh()->state());
        $this->assertEquals(1500, $this->balances($trip)['collections']);

        // A second amount disputed and found not received is cancelled for good.
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 400]);
        $second = TripCollection::query()->latest('id')->firstOrFail();
        Tenant::forCompany($this->co->id, fn () => app(CollectionService::class)->dispute($second, 'client', Actor::system()));
        $this->post("/office/trips/{$trip->id}/collections/{$second->id}/resolve", ['resolution' => 'cancelled']);
        $this->assertSame('cancelled', $second->fresh()->state());
        $this->assertEquals(1500, $this->balances($trip)['collections']);
    }

    public function test_a_hired_truck_trip_gets_no_custody(): void
    {
        $this->setUpFleet();
        $hired = \App\Models\Vehicle::factory()->hired()->for($this->co)->create();
        $this->actingAs($this->admin, 'web')->post('/office/trips', $this->tripData(['vehicle_id' => $hired->id, 'driver_id' => $this->drv->id]));
        $trip = \App\Models\Trip::query()->firstOrFail();

        $this->post("/office/trips/{$trip->id}/step", ['step' => 'accept'])->assertSessionHas('success');
        $this->post("/office/trips/{$trip->id}/custody", ['amount' => 1000])->assertSessionHas('error');
        $this->expense($trip->id, ['paid_from' => 'custody', 'amount' => 100])->assertSessionHas('error');
        $this->assertSame(0, WalletEntry::query()->count());
    }

    public function test_the_wallets_screen_shows_the_cash_outside_the_safe(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 2500]);

        $this->get('/office/wallets?tab=balances')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Wallets/Index')
            ->where('tiles.custody', 3000)
            ->where('tiles.collections', 2500)
            ->where('tiles.unconfirmed', 2500)
            ->where('balances.0.name', $this->drv->name)
            ->where('balances.0.held', 5500));

        $this->actingAs($this->officeUser($this->co, ['trips.view']), 'web')->get('/office/wallets')->assertForbidden();
    }
}
