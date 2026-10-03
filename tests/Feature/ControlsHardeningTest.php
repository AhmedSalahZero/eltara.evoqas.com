<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\WalletTransfer;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the money and permission controls (audit Q2–Q5)
//  Location: tests/Feature/ControlsHardeningTest.php
//  · nobody gives themselves permissions, or hands out ones they lack
//  · changing transfer rules has its own permission
//  · nobody approves their own transfer or settles a dispute on cash
//    they recorded themselves (the company admin may)
//  · custody has a ceiling per trip; only the company admin passes it
// ══════════════════════════════════════════════════════════════════

class ControlsHardeningTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    // ── Q2: no self-promotion ──────────────────────────────────────

    public function test_a_user_cannot_change_their_own_permissions(): void
    {
        $company = $this->company();
        $manager = $this->officeUser($company, ['users.view', 'users.edit', 'trips.view']);

        $this->actingAs($manager, 'web')->put("/office/users/{$manager->id}/permissions", ['permissions' => ['users.edit', 'month_close.reopen']])
            ->assertSessionHas('error');

        $this->assertNotContains('month_close.reopen', $manager->fresh()->permissions);
    }

    public function test_a_user_can_only_give_permissions_they_hold(): void
    {
        $company = $this->company();
        $manager = $this->officeUser($company, ['users.view', 'users.edit', 'trips.view']);
        $other = $this->officeUser($company);

        $this->actingAs($manager, 'web')->put("/office/users/{$other->id}/permissions", ['permissions' => ['trip_settlement.approve']])
            ->assertSessionHas('error');
        $this->assertSame([], $other->fresh()->permissions);

        $this->put("/office/users/{$other->id}/permissions", ['permissions' => ['trips.view']])->assertSessionHas('success');
        $this->assertContains('trips.view', $other->fresh()->permissions);
    }

    public function test_a_user_cannot_raise_an_approval_limit_above_their_own(): void
    {
        $company = $this->company();
        $manager = $this->officeUser($company, ['users.view', 'users.edit', 'wallet_transfers.approve'], ['approval_limit' => 1000]);
        $other = $this->officeUser($company);

        $this->actingAs($manager, 'web')->put("/office/users/{$other->id}/permissions", ['permissions' => ['wallet_transfers.approve'], 'approval_limit' => 50000])
            ->assertSessionHas('error');
        $this->put("/office/users/{$other->id}/permissions", ['permissions' => ['wallet_transfers.approve'], 'approval_limit' => 800])
            ->assertSessionHas('success');
    }

    public function test_copying_permissions_from_a_stronger_user_is_refused_for_a_non_admin(): void
    {
        $company = $this->company();
        $manager = $this->officeUser($company, ['users.view', 'users.create']);
        $strong = $this->officeUser($company, ['trip_settlement.approve', 'month_close.reopen']);

        $this->actingAs($manager, 'web')->post('/office/users', ['name' => 'N', 'email' => 'n@example.test', 'language' => 'ar', 'copy_from_user_id' => $strong->id])
            ->assertSessionHas('error');
        $this->assertDatabaseMissing('users', ['email' => 'n@example.test']);
    }

    public function test_the_company_admin_can_still_give_any_permission(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $user = $this->officeUser($company);

        $this->actingAs($admin, 'web')->put("/office/users/{$user->id}/permissions", ['permissions' => ['month_close.reopen']])->assertSessionHas('success');
        $this->assertContains('month_close.reopen', $user->fresh()->permissions);
    }

    // ── Q3: transfer rules have their own permission ───────────────

    public function test_changing_a_trips_transfer_rule_needs_its_own_permission(): void
    {
        $trip = $this->runningTrip('approval');

        $editor = $this->officeUser($this->co, ['trips.view', 'trips.edit']);
        $this->actingAs($editor, 'web')->put("/office/trips/{$trip->id}/policy", ['transfer_policy' => 'auto'])->assertForbidden();
        $this->assertSame('approval', $trip->fresh()->transfer_policy);

        $owner = $this->officeUser($this->co, ['trips.view', 'trips.edit_policy']);
        $this->actingAs($owner, 'web')->put("/office/trips/{$trip->id}/policy", ['transfer_policy' => 'auto'])->assertSessionHas('success');
        $this->assertSame('auto', $trip->fresh()->transfer_policy);
    }

    public function test_a_new_trip_made_without_that_permission_gets_the_company_default_rule(): void
    {
        $this->setUpFleet();
        $creator = $this->officeUser($this->co, ['trips.view', 'trips.create']);

        $this->actingAs($creator, 'web')->post('/office/trips', $this->tripData(['transfer_policy' => 'auto', 'auto_transfer_limit' => 99999]))->assertSessionHasNoErrors();

        $trip = Trip::query()->firstOrFail();
        $this->assertSame('limit', $trip->transfer_policy);
        $this->assertEquals(1000, $trip->auto_transfer_limit);
    }

    public function test_the_company_wide_rule_in_settings_needs_the_same_permission(): void
    {
        $this->setUpFleet();
        $user = $this->officeUser($this->co, ['settings.view', 'settings.edit']);
        $same = [
            'default_transfer_policy' => 'limit', 'auto_transfer_limit' => 1000, 'custody_buffer_percent' => 5, 'personal_spend_to_advance' => true,
            'month_split_rule' => 'hours', 'ga_basis' => 'own_km', 'ga_rate_estimate' => null, 'receipt_photo_required' => true, 'capture_location' => true,
            'offline_mode' => true, 'max_hours_without_sync' => 12, 'diesel_price' => 20.5, 'currency' => 'EGP', 'default_language' => 'ar', 'default_theme' => 'dark',
        ];

        $this->actingAs($user, 'web')->put('/office/settings', array_merge($same, ['default_transfer_policy' => 'auto']))->assertSessionHas('error');
        $this->assertSame('limit', CompanySetting::for($this->co->id)->default_transfer_policy);

        // Changing something else is still fine.
        $this->put('/office/settings', array_merge($same, ['diesel_price' => 22]))->assertSessionHas('success');
    }

    // ── Q4: separation of duties ───────────────────────────────────

    public function test_nobody_approves_or_rejects_their_own_transfer(): void
    {
        $trip = $this->runningTrip('approval');
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);

        $clerk = $this->officeUser($this->co, ['wallet_transfers.view', 'wallet_transfers.create', 'wallet_transfers.approve'], ['approval_limit' => 5000]);
        $this->actingAs($clerk, 'web')->post("/office/trips/{$trip->id}/transfers", ['amount' => 500, 'reason' => 'Diesel'])->assertSessionHas('success');
        $transfer = WalletTransfer::query()->firstOrFail();

        $this->post("/office/transfers/{$transfer->id}/approve")->assertSessionHas('error');
        $this->post("/office/transfers/{$transfer->id}/reject", ['note' => 'x'])->assertSessionHas('error');
        $this->assertSame('pending', $transfer->fresh()->status);

        // Another approver decides it.
        $other = $this->officeUser($this->co, ['wallet_transfers.view', 'wallet_transfers.approve'], ['approval_limit' => 5000]);
        $this->actingAs($other, 'web')->post("/office/transfers/{$transfer->id}/approve")->assertSessionHas('success');
    }

    public function test_the_person_who_recorded_cash_cannot_settle_its_dispute(): void
    {
        $trip = $this->runningTrip();
        $clerk = $this->officeUser($this->co, ['trips.view', 'wallet_transfers.view', 'wallet_transfers.create', 'wallet_transfers.approve'], ['approval_limit' => 5000]);
        $this->actingAs($clerk, 'web')->post("/office/trips/{$trip->id}/collections", ['amount' => 1500])->assertSessionHas('success');
        $collection = TripCollection::query()->firstOrFail();
        Tenant::forCompany($this->co->id, fn () => app(CollectionService::class)->dispute($collection, 'client', Actor::system(), 'Paid less'));

        $this->post("/office/trips/{$trip->id}/collections/{$collection->id}/resolve", ['resolution' => 'accepted'])->assertSessionHas('error');
        $this->assertTrue($collection->fresh()->isOpenDispute());

        $other = $this->officeUser($this->co, ['trips.view', 'wallet_transfers.view', 'wallet_transfers.approve'], ['approval_limit' => 5000]);
        $this->actingAs($other, 'web')->post("/office/trips/{$trip->id}/collections/{$collection->id}/resolve", ['resolution' => 'accepted'])->assertSessionHas('success');
    }

    // ── Q5: custody ceiling ────────────────────────────────────────

    public function test_custody_above_the_ceiling_is_refused_for_office_users_but_the_admin_may(): void
    {
        $trip = $this->runningTrip();               // planned 3,000, issued 3,000 → ceiling 4,500 (+50%)
        $clerk = $this->officeUser($this->co, ['trips.view', 'wallet_transfers.view', 'wallet_transfers.create']);

        $this->actingAs($clerk, 'web')->post("/office/trips/{$trip->id}/custody", ['amount' => 1000])->assertSessionHas('success');
        $this->travel(10)->seconds();
        $this->post("/office/trips/{$trip->id}/custody", ['amount' => 1000])->assertSessionHas('error');
        $this->assertEquals(4000, $this->balances($trip)['custody']);

        $this->travel(10)->seconds();
        $this->actingAs($this->admin, 'web')->post("/office/trips/{$trip->id}/custody", ['amount' => 1000])->assertSessionHas('success');
        $this->assertEquals(5000, $this->balances($trip)['custody']);
    }
}
