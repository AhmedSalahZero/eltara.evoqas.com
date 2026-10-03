<?php

namespace Tests\Feature;

use App\Notifications\SubscriptionEndingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: subscription ending → warnings, then read-only
//  Location: tests/Feature/SubscriptionReadOnlyTest.php
//  Feature doc: docs/STEP_01_FOUNDATION.md §5
// ══════════════════════════════════════════════════════════════════

class SubscriptionReadOnlyTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_after_the_end_date_the_office_can_look_but_not_change(): void
    {
        $company = $this->company(['subscription_ends_at' => today()->subDay()]);
        $admin = $this->companyAdmin($company);
        $user = $this->officeUser($company);

        $this->actingAs($admin, 'web')->get('/office/users')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.company.read_only', true));

        $this->put("/office/users/{$user->id}/permissions", ['permissions' => ['trips.view']])->assertRedirect();
        $this->assertSame([], $user->fresh()->permissions);

        // Language / theme still work.
        $this->post('/preferences', ['theme' => 'light'])->assertRedirect();
        $this->assertSame('light', $admin->fresh()->theme);
    }

    public function test_the_banner_warns_within_30_days(): void
    {
        $admin = $this->companyAdmin($this->company(['subscription_ends_at' => today()->addDays(10)]));

        $this->actingAs($admin, 'web')->get('/office')
            ->assertInertia(fn (Assert $page) => $page->where('auth.company.expiring_soon', true)->where('auth.company.days_left', 10));
    }

    public function test_the_daily_reminder_emails_the_admin_once_a_week(): void
    {
        Notification::fake();
        $admin = $this->companyAdmin($this->company(['subscription_ends_at' => today()->addDays(20)]));
        $this->companyAdmin($this->company(['subscription_ends_at' => today()->addDays(200)]));

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();
        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Notification::assertSentToTimes($admin, SubscriptionEndingNotification::class, 1);
        Notification::assertCount(1);
    }
}
