<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\ExpenseCategory;
use App\Services\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: company settings and expense categories (Step 2)
//  Location: tests/Feature/CompanySettingsTest.php
//  Feature doc: docs/STEP_02_MASTER_DATA.md §5
// ══════════════════════════════════════════════════════════════════

class CompanySettingsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function data(array $overrides = []): array
    {
        return array_merge([
            'default_transfer_policy' => 'approval', 'auto_transfer_limit' => 1500, 'custody_buffer_percent' => 7,
            'personal_spend_to_advance' => true, 'month_split_rule' => 'hours', 'ga_basis' => 'own_km', 'ga_rate_estimate' => 4.25,
            'receipt_photo_required' => true, 'capture_location' => true, 'offline_mode' => true, 'max_hours_without_sync' => 24,
            'diesel_price' => 21.75, 'currency' => 'EGP', 'default_language' => 'en', 'default_theme' => 'light',
        ], $overrides);
    }

    public function test_every_company_starts_with_the_default_rules_and_the_ten_standard_categories(): void
    {
        $admin = $this->companyAdmin();

        $this->actingAs($admin, 'web')->get('/office/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Settings/Index')
            ->where('settings.default_transfer_policy', 'limit')
            ->where('settings.auto_transfer_limit', 1000)
            ->where('settings.custody_buffer_percent', 5)
            ->where('settings.month_split_rule', 'hours')
            ->has('categories', 10));
    }

    public function test_new_companies_get_their_defaults_when_created(): void
    {
        $this->actingAs($this->superAdmin(), 'web')->post('/admin/companies', [
            'name_ar' => 'شركة', 'name_en' => 'New Co', 'status' => 'trial', 'office_users_limit' => 5, 'driver_accounts_limit' => 10,
            'subscription_starts_at' => today()->toDateString(), 'subscription_ends_at' => today()->addMonth()->toDateString(),
            'default_language' => 'ar', 'admin_name' => 'Admin', 'admin_email' => 'admin@newco.test',
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, ExpenseCategory::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, CompanySetting::query()->withoutGlobalScopes()->count());
    }

    public function test_saving_settings_updates_the_rules_and_the_company_defaults_and_is_audited(): void
    {
        $admin = $this->companyAdmin();

        $this->actingAs($admin, 'web')->put('/office/settings', $this->data())->assertSessionHasNoErrors();

        $settings = CompanySetting::for($admin->company_id);
        $this->assertSame('approval', $settings->default_transfer_policy);
        $this->assertEquals(21.75, $settings->diesel_price);
        $this->assertSame('en', $admin->company->fresh()->default_language);

        $log = AuditLog::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertSame('limit', $log->changes['before']['default_transfer_policy']);
        $this->assertSame('approval', $log->changes['after']['default_transfer_policy']);
    }

    public function test_unknown_values_are_refused(): void
    {
        $this->actingAs($this->companyAdmin(), 'web')
            ->put('/office/settings', $this->data(['default_transfer_policy' => 'never', 'max_hours_without_sync' => 5]))
            ->assertSessionHasErrors(['default_transfer_policy', 'max_hours_without_sync']);
    }

    public function test_viewing_needs_settings_view_and_changing_needs_settings_edit(): void
    {
        $company = $this->company();

        $this->actingAs($this->officeUser($company, ['settings.view']), 'web')->get('/office/settings')->assertOk();
        $this->put('/office/settings', $this->data())->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->officeUser($company), 'web')->get('/office/settings')->assertForbidden();
    }

    public function test_a_company_adds_and_edits_its_own_expense_categories(): void
    {
        $admin = $this->companyAdmin();
        CompanyDefaults::ensure($admin->company_id);

        $this->actingAs($admin, 'web')->post('/office/settings/categories', ['name_ar' => 'نظافة', 'icon' => 'box', 'cash_percent' => 100])->assertSessionHasNoErrors();
        $category = ExpenseCategory::query()->where('name_ar', 'نظافة')->firstOrFail();
        $this->assertFalse($category->is_system);

        $fuel = ExpenseCategory::query()->where('code', 'fuel')->firstOrFail();
        $this->patch("/office/settings/categories/{$fuel->id}", ['name_ar' => 'سولار', 'icon' => 'fuel', 'cash_percent' => 30, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame(30, $fuel->fresh()->cash_percent);
    }

    public function test_categories_of_another_company_are_not_found(): void
    {
        $admin = $this->companyAdmin();
        $other = $this->company();
        CompanyDefaults::ensure($other->id);
        $foreign = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $other->id)->firstOrFail();

        $this->actingAs($admin, 'web')->patch("/office/settings/categories/{$foreign->id}", ['name_ar' => 'x', 'icon' => 'box', 'cash_percent' => 0])->assertNotFound();
    }

    public function test_a_read_only_company_can_look_but_not_save(): void
    {
        $admin = $this->companyAdmin($this->company(['subscription_ends_at' => today()->subDay()]));

        $this->actingAs($admin, 'web')->get('/office/settings')->assertOk();
        $this->put('/office/settings', $this->data())->assertRedirect();
        $this->assertSame('limit', CompanySetting::for($admin->company_id)->default_transfer_policy);
    }
}
