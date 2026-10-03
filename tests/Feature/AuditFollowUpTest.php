<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TripCollection;
use App\Models\TripExpense;
use App\Models\TripSettlement;
use App\Models\WalletTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: audit follow-ups Q7–Q13
//  Location: tests/Feature/AuditFollowUpTest.php
//  Q7  a rejected transfer withdraws the expense that waited on it
//  Q8  custody below zero is allowed but flagged in the audit log
//  Q9  office cash (not the admin's) waits for the driver to confirm
//  Q10 settlement records who received the cash
//  Q11 unconfirmed cash must be accepted on purpose to settle
//  Q12 spending on a category with no standard is flagged
//  Q13 a phone's entry times are kept inside a window
// ══════════════════════════════════════════════════════════════════

class AuditFollowUpTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('trip_files');
    }

    public function test_a_rejected_transfer_withdraws_the_expense_that_waited_on_it(): void
    {
        $trip = $this->runningTrip('approval');
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);
        $this->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['repair'], 'paid_from' => 'collections', 'amount' => 450])->assertSessionHas('success');
        $this->assertEquals(2550, $this->balances($trip)['custody']);

        $transfer = WalletTransfer::query()->firstOrFail();
        $this->post("/office/transfers/{$transfer->id}/reject", ['note' => 'Not allowed'])->assertSessionHas('success');

        $this->assertSame('rejected', $transfer->fresh()->status);
        $this->assertSame(0, TripExpense::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->count());
        $this->assertEquals(3000, $this->balances($trip)['custody']);
        $this->assertEquals(2000, $this->balances($trip)['collections']);
    }

    public function test_custody_may_go_below_zero_but_the_audit_log_says_so(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 3400])
            ->assertSessionHas('success');

        $this->assertEquals(-400, $this->balances($trip)['custody']);
        $log = AuditLog::query()->where('action', 'expense.created')->firstOrFail();
        $this->assertTrue($log->changes['after']['overdrawn']);
        $this->assertEquals(-400, $log->changes['after']['custody_left']);
    }

    public function test_office_cash_waits_for_the_driver_unless_the_company_admin_records_it(): void
    {
        $trip = $this->runningTrip();
        $clerk = $this->officeUser($this->co, ['trips.view', 'wallet_transfers.view', 'wallet_transfers.create']);

        $this->actingAs($clerk, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 1500])->assertSessionHas('success');
        $this->assertSame('awaiting_driver', TripCollection::query()->firstOrFail()->state());
        $this->assertEquals(0, $this->balances($trip)['collections']);

        $this->travel(10)->seconds();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 2000])->assertSessionHas('success');
        $this->assertEquals(2000, $this->balances($trip)['collections']);
        $this->assertTrue(AuditLog::query()->where('action', 'collection.recorded')->get()->contains(fn ($l) => ($l->changes['after']['admin_override'] ?? false) === true));
    }

    public function test_the_settlement_records_who_received_the_cash(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/deliver", ['pod' => $this->pod(), 'receiver' => 'Store keeper']);

        $this->post("/office/trips/{$trip->id}/settle", ['received_by' => 'Cashier Omar'])->assertSessionHas('success');

        $this->assertSame('Cashier Omar', TripSettlement::query()->firstOrFail()->cash_received_by);
    }

    public function test_without_a_name_the_person_settling_is_the_receiver(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/deliver", ['pod' => $this->pod(), 'receiver' => 'Store keeper']);

        $this->post("/office/trips/{$trip->id}/settle")->assertSessionHas('success');

        $this->assertSame($this->admin->name, TripSettlement::query()->firstOrFail()->cash_received_by);
    }

    public function test_unconfirmed_cash_must_be_accepted_on_purpose(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);
        $this->post("/office/trips/{$trip->id}/deliver", ['pod' => $this->pod(), 'receiver' => 'Store keeper']);

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $page) => $page->where('settlement.needs_ack', true));
        $this->post("/office/trips/{$trip->id}/settle")->assertSessionHas('error');
        $this->assertSame(0, TripSettlement::query()->count());

        $this->travel(10)->seconds();
        $this->post("/office/trips/{$trip->id}/settle", ['accept_unconfirmed' => 1])->assertSessionHas('success');
        $this->assertEquals(2000, TripSettlement::query()->firstOrFail()->unconfirmed_amount);
    }

    public function test_spending_on_a_category_with_no_standard_is_flagged(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/expenses", ['expense_category_id' => $this->cats['repair'], 'paid_from' => 'custody', 'amount' => 100]);

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $page) => $page
            ->where('figures.budget.rows', fn ($rows) => collect($rows)->firstWhere('category_id', $this->cats['repair'])['over'] === true));
    }

    public function test_a_phones_entry_time_is_kept_inside_the_allowed_window(): void
    {
        $trip = $this->runningTrip('limit', 1000, ['receipt_photo_required' => false]);
        $this->actingAs($this->drv, 'driver');

        $send = fn (string $at) => $this->postJson('/driver/api/sync', ['items' => [[
            'uuid' => (string) Str::uuid(), 'type' => 'trip.expense', 'recorded_at' => $at,
            'payload' => ['trip_id' => $trip->id, 'expense_category_id' => $this->cats['toll'], 'paid_from' => 'custody', 'amount' => 50],
        ]]])->assertJsonPath('results.0.status', 'applied');

        $send(now()->addDays(3)->utc()->toIso8601ZuluString());
        $send(now()->subDays(40)->utc()->toIso8601ZuluString());

        $times = TripExpense::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->orderBy('id')->pluck('spent_at');
        $this->assertTrue($times[0]->lessThanOrEqualTo(now()->addMinute()), 'a future time is pulled back to now');
        $this->assertTrue($times[1]->greaterThanOrEqualTo(now()->subDays(7)->subMinute()), 'a very old time is pulled up to the window');
    }
}
