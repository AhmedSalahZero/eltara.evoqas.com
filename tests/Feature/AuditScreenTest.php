<?php

namespace Tests\Feature;

use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the audit log screen (Step 7, Scope §12)
//  Location: tests/Feature/AuditScreenTest.php
//  Feature doc: docs/STEP_07_DASHBOARD_REPORTS_NOTIFICATIONS_AUDIT.md
//  Company admin only; own company only; filters; Excel export.
// ══════════════════════════════════════════════════════════════════

class AuditScreenTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    public function test_the_company_admin_sees_the_log_with_readable_action_names(): void
    {
        $this->setUpFleet();
        $this->admin->forceFill(['language' => 'en'])->save(); // this test reads the English wording
        $this->actingAs($this->admin, 'web');
        Audit::record('trip.price_changed', null, ['before' => ['price' => 9000], 'after' => ['price' => 9500]], $this->co->id);

        $this->get('/office/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Audit/Index')
            ->where('logs.data.0.action', 'trip.price_changed')
            ->where('logs.data.0.label', 'Trip price changed')
            ->where('logs.data.0.actor', $this->admin->name)
            ->where('logs.data.0.changes.after.price', 9500)
            ->where('areas.0.key', 'trip'));
    }

    public function test_other_office_users_cannot_open_it_even_with_every_permission(): void
    {
        $this->setUpFleet();
        $keys = collect(config('permissions.features'))->flatMap(fn ($f, $k) => array_map(fn ($a) => "{$k}.{$a}", $f['actions']))->all();
        $user = $this->officeUser($this->co, $keys);

        $this->actingAs($user, 'web')->get('/office/audit')->assertForbidden();
        $this->get('/office/audit/export')->assertForbidden();
    }

    public function test_only_the_own_company_is_shown_and_filters_work(): void
    {
        $this->setUpFleet();
        $other = $this->company();
        Audit::record('month.closed', null, ['month' => '2026-08'], $this->co->id);
        Audit::record('permissions.changed', null, [], $this->co->id);
        Audit::record('month.closed', null, ['month' => 'SECRET'], $other->id);

        $this->actingAs($this->admin, 'web')->get('/office/audit')->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
        $this->get('/office/audit?area=month')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'month.closed'));
        $this->get('/office/audit?q=2026-08')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        $this->get('/office/audit?from='.now()->addDay()->toDateString())->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
        $this->get('/office/audit?q=SECRET')->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
    }

    public function test_the_log_exports_to_excel(): void
    {
        $this->setUpFleet();
        Audit::record('month.closed', null, ['month' => '2026-08'], $this->co->id);

        $response = $this->actingAs($this->admin, 'web')->get('/office/audit/export');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
    }

    public function test_the_menu_item_is_for_admins_only(): void
    {
        $this->setUpFleet();
        $user = $this->officeUser($this->co, ['dashboard.view']);

        $this->actingAs($this->admin, 'web')->get('/office')->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'company_admin'));
        $this->actingAs($user, 'web')->get('/office')->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'office_user'));
    }
}
