<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ActivateAccountNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: users & per-user permissions inside a company
//  Location: tests/Feature/UsersAndPermissionsTest.php
//  Feature doc: docs/STEP_01_FOUNDATION.md §4
// ══════════════════════════════════════════════════════════════════

class UsersAndPermissionsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_the_company_admin_sees_the_users_screen_with_the_permission_grid(): void
    {
        $admin = $this->companyAdmin();

        $this->actingAs($admin, 'web')->get('/office/users')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Office/Users/Index')->has('users', 1)->has('matrix', 16)->where('limits.office_used', 1));
    }

    public function test_an_office_user_without_the_permission_cannot_open_or_change_users(): void
    {
        $company = $this->company();
        $user = $this->officeUser($company, ['trips.view']);
        $other = $this->officeUser($company);

        $this->actingAs($user, 'web')->get('/office/users')->assertForbidden();
        $this->put("/office/users/{$other->id}/permissions", ['permissions' => ['users.view']])->assertForbidden();
        $this->get('/office/trips')->assertOk();
        $this->get('/office/reports')->assertForbidden(); // Reports is a real screen now (it was a "coming soon" page before)
    }

    public function test_inviting_a_user_sends_an_activation_email_and_can_copy_permissions(): void
    {
        Notification::fake();
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $source = $this->officeUser($company, ['trips.view', 'trips.create'], ['approval_limit' => 500]);

        $this->actingAs($admin, 'web')->post('/office/users', [
            'name' => 'Omar', 'email' => 'omar@example.test', 'language' => 'ar', 'copy_from_user_id' => $source->id,
        ])->assertSessionHas('success');

        $omar = User::query()->where('email', 'omar@example.test')->firstOrFail();
        $this->assertEqualsCanonicalizing(['trips.view', 'trips.create'], $omar->permissions);
        $this->assertEquals(500, $omar->approval_limit);
        Notification::assertSentTo($omar, ActivateAccountNotification::class);
    }

    public function test_the_office_users_limit_is_enforced(): void
    {
        $company = $this->company(['office_users_limit' => 2]);
        $admin = $this->companyAdmin($company);
        $this->officeUser($company);

        $this->actingAs($admin, 'web')->post('/office/users', ['name' => 'X', 'email' => 'x@example.test', 'language' => 'ar'])->assertSessionHas('error');
        $this->assertFalse(User::query()->where('email', 'x@example.test')->exists());
    }

    public function test_saving_permissions_cleans_them_adds_view_and_is_audited(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $user = $this->officeUser($company);

        $this->actingAs($admin, 'web')->put("/office/users/{$user->id}/permissions", [
            'permissions' => ['trips.edit', 'made.up'],
        ])->assertSessionHas('success');

        $this->assertEqualsCanonicalizing(['trips.edit', 'trips.view'], $user->fresh()->permissions);
        $this->assertTrue(AuditLog::query()->where('action', 'permissions.changed')->where('subject_id', $user->id)->exists());

        // The new permission works at once.
        $this->actingAs($user->fresh(), 'web')->get('/office/trips')->assertOk();
    }

    public function test_approving_wallet_transfers_needs_an_approval_limit(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $user = $this->officeUser($company);

        $this->actingAs($admin, 'web')->put("/office/users/{$user->id}/permissions", ['permissions' => ['wallet_transfers.approve']])
            ->assertSessionHasErrors('approval_limit');

        $this->put("/office/users/{$user->id}/permissions", ['permissions' => ['wallet_transfers.approve'], 'approval_limit' => 1000])
            ->assertSessionHasNoErrors();
        $this->assertEquals(1000, $user->fresh()->approval_limit);
    }

    public function test_the_company_admin_cannot_be_limited_or_suspended_and_nobody_can_suspend_themselves(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $manager = $this->officeUser($company, ['users.view', 'users.edit']);

        $this->actingAs($manager, 'web')->put("/office/users/{$admin->id}/permissions", ['permissions' => []])->assertSessionHas('error');
        $this->post("/office/users/{$admin->id}/toggle")->assertSessionHas('error');
        $this->post("/office/users/{$manager->id}/toggle")->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_suspending_frees_a_place_and_reactivating_needs_a_free_place(): void
    {
        $company = $this->company(['office_users_limit' => 2]);
        $admin = $this->companyAdmin($company);
        $user = $this->officeUser($company);

        $this->actingAs($admin, 'web')->post("/office/users/{$user->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(1, $company->officeSeatsUsed());

        $this->post('/office/users', ['name' => 'New', 'email' => 'new@example.test', 'language' => 'ar'])->assertSessionHas('success');

        $this->post("/office/users/{$user->id}/toggle")->assertSessionHas('error');
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_users_of_another_company_cannot_be_touched(): void
    {
        $admin = $this->companyAdmin();
        $stranger = $this->officeUser($this->company());

        $this->actingAs($admin, 'web')->put("/office/users/{$stranger->id}/permissions", ['permissions' => ['trips.view']])->assertNotFound();
        $this->post("/office/users/{$stranger->id}/toggle")->assertNotFound();
        $this->assertSame([], $stranger->fresh()->permissions);
    }
}
