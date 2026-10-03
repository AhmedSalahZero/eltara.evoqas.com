<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\ActivateAccountNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: Super Admin — companies and limits
//  Location: tests/Feature/SuperAdminCompaniesTest.php
//  Feature doc: docs/STEP_01_FOUNDATION.md §3
// ══════════════════════════════════════════════════════════════════

class SuperAdminCompaniesTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function newCompany(array $overrides = []): array
    {
        return array_merge([
            'name_ar' => 'شركة الاختبار', 'name_en' => 'Test Haulage', 'status' => 'trial',
            'office_users_limit' => 5, 'driver_accounts_limit' => 15,
            'subscription_starts_at' => today()->toDateString(), 'subscription_ends_at' => today()->addDays(30)->toDateString(),
            'default_language' => 'ar', 'default_theme' => 'dark',
            'admin_name' => 'Sara Admin', 'admin_email' => 'Sara@Test-Haulage.test', 'admin_phone' => '01112223334',
        ], $overrides);
    }

    public function test_only_the_super_admin_can_open_the_platform_area(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->actingAs($this->companyAdmin(), 'web')->get('/admin')->assertRedirect('/');

        $this->actingAs($this->superAdmin(), 'web')->get('/admin')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')->has('stats')->has('companies'));
    }

    public function test_creating_a_company_creates_its_admin_sends_the_activation_email_and_is_audited(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'web')->post('/admin/companies', $this->newCompany())->assertSessionHas('success');

        $company = Company::query()->where('name_en', 'Test Haulage')->firstOrFail();
        $companyAdmin = User::query()->where('email', 'sara@test-haulage.test')->firstOrFail();

        $this->assertSame($company->id, $companyAdmin->company_id);
        $this->assertTrue($companyAdmin->isCompanyAdmin());
        $this->assertNull($companyAdmin->email_verified_at);
        Notification::assertSentTo($companyAdmin, ActivateAccountNotification::class);
        $this->assertTrue(AuditLog::query()->where('action', 'company.created')->where('subject_id', $company->id)->exists());
    }

    public function test_the_admin_email_cannot_already_belong_to_anyone(): void
    {
        $existing = $this->officeUser();
        $client = $this->clientUser();

        $this->actingAs($this->superAdmin(), 'web')
            ->post('/admin/companies', $this->newCompany(['admin_email' => $existing->email]))->assertSessionHasErrors('admin_email');
        $this->post('/admin/companies', $this->newCompany(['admin_email' => $client->email]))->assertSessionHasErrors('admin_email');
    }

    public function test_a_limit_cannot_go_below_what_is_already_used(): void
    {
        $company = $this->company(['office_users_limit' => 5]);
        $this->companyAdmin($company);
        $this->officeUser($company);
        $this->officeUser($company);

        $data = collect($company->toArray())->only(['name_ar', 'name_en', 'default_language', 'default_theme'])->all() + [
            'status' => 'active', 'office_users_limit' => 2, 'driver_accounts_limit' => 15,
            'subscription_starts_at' => today()->toDateString(), 'subscription_ends_at' => today()->addYear()->toDateString(),
        ];

        $this->actingAs($this->superAdmin(), 'web')->patch("/admin/companies/{$company->id}", $data)->assertSessionHasErrors('office_users_limit');

        $data['office_users_limit'] = 3;
        $this->patch("/admin/companies/{$company->id}", $data)->assertSessionHasNoErrors();
        $this->assertSame(3, $company->fresh()->office_users_limit);

        $log = AuditLog::query()->where('action', 'company.updated')->firstOrFail();
        $this->assertSame(5, $log->changes['before']['office_users_limit']);
        $this->assertSame(3, $log->changes['after']['office_users_limit']);
    }

    public function test_resending_the_activation_email(): void
    {
        Notification::fake();
        $company = $this->company();
        $admin = User::factory()->companyAdmin($company)->notActivated()->create();

        $this->actingAs($this->superAdmin(), 'web')->post("/admin/companies/{$company->id}/activation")->assertSessionHas('success');
        Notification::assertSentTo($admin, ActivateAccountNotification::class);
    }

    public function test_the_companies_list_searches_and_shows_usage(): void
    {
        $nile = $this->company(['name_en' => 'Nile Heavy']);
        $this->companyAdmin($nile);
        $this->driver($nile);
        $this->company(['name_en' => 'Delta Freight']);

        $this->actingAs($this->superAdmin(), 'web')->get('/admin/companies?search=Nile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Companies/Index')
                ->has('companies.data', 1)
                ->where('companies.data.0.office_used', 1)
                ->where('companies.data.0.drivers_used', 1));
    }
}
