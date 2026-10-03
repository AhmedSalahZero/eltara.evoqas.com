<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\RateCard;
use App\Models\TripRoute;
use App\Services\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: routes and standard budgets (Step 2, Scope §6.6)
//  Location: tests/Feature/RoutesAndBudgetsTest.php
//  Feature doc: docs/STEP_02_MASTER_DATA.md §4
// ══════════════════════════════════════════════════════════════════

class RoutesAndBudgetsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function data(int $companyId, array $overrides = []): array
    {
        CompanyDefaults::ensure($companyId);
        $cats = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $companyId)->pluck('id', 'code');

        return array_merge([
            'origin_ar' => 'العبور', 'origin_en' => 'Obour', 'destination_ar' => 'ميناء الإسكندرية', 'destination_en' => 'Alexandria Port',
            'weight_tons' => 5, 'km_round_trip' => 450, 'usual_hours' => 17, 'is_active' => true,
            'budgets' => [$cats['fuel'] => 3450, $cats['toll'] => 260, $cats['weigh'] => 120, $cats['allow'] => 600, $cats['labor'] => 450, $cats['night'] => ''],
        ], $overrides);
    }

    public function test_a_route_needs_its_weight_and_shows_it_in_its_name(): void
    {
        $admin = $this->companyAdmin();
        $this->actingAs($admin, 'web');

        $this->post('/office/routes', array_diff_key($this->data($admin->company_id), ['weight_tons' => 1]))->assertSessionHasErrors('weight_tons');
        $this->post('/office/routes', $this->data($admin->company_id, ['weight_tons' => 0]))->assertSessionHasErrors('weight_tons');
        $this->assertSame(0, TripRoute::query()->count());

        $this->post('/office/routes', $this->data($admin->company_id, ['weight_tons' => 5]))->assertSessionHasNoErrors();
        $route = TripRoute::query()->firstOrFail();
        $this->assertSame(5.0, $route->weight_tons);
        $this->assertSame('Obour → Alexandria Port – 5 Ton', $route->displayName('en'));
        $this->assertSame('العبور ← ميناء الإسكندرية – 5 طن', $route->displayName('ar'));

        // The same places with another weight is a second route.
        $this->post('/office/routes', $this->data($admin->company_id, ['weight_tons' => 12.5]))->assertSessionHasNoErrors();
        $this->assertSame(2, TripRoute::query()->count());
        $this->assertSame('Obour → Alexandria Port – 12.5 Ton', TripRoute::query()->where('weight_tons', 12.5)->firstOrFail()->displayName('en'));
    }

    public function test_a_route_with_its_budget_shows_the_totals_and_the_suggested_custody(): void
    {
        $admin = $this->companyAdmin();

        $this->actingAs($admin, 'web')->post('/office/routes', $this->data($admin->company_id))->assertSessionHasNoErrors();

        // Cash road costs = 40% of fuel (1,380) + tolls, weigh, allowance, labour (1,430) = 2,810
        // Suggested custody = 2,810 × 1.05 = 2,950.5 → rounded up to 500 → 3,000
        $this->get('/office/routes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Routes/Index')
            ->where('routes.0.standard', 4880)
            ->where('routes.0.cash', 2810)
            ->where('routes.0.custody', 3000)
            ->where('routes.0.per_km', 10.84));

        $this->assertCount(5, TripRoute::query()->firstOrFail()->budgets);
    }

    public function test_budget_lines_for_another_companys_categories_are_ignored(): void
    {
        $admin = $this->companyAdmin();
        $other = $this->company();
        CompanyDefaults::ensure($other->id);
        $foreignCategory = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $other->id)->value('id');

        $data = $this->data($admin->company_id);
        $data['budgets'][$foreignCategory] = 99999;

        $this->actingAs($admin, 'web')->post('/office/routes', $data)->assertSessionHasNoErrors();
        $this->assertFalse(TripRoute::query()->firstOrFail()->budgets->contains('expense_category_id', $foreignCategory));
    }

    public function test_updating_a_route_replaces_its_budget_and_is_audited(): void
    {
        $admin = $this->companyAdmin();
        $this->actingAs($admin, 'web')->post('/office/routes', $this->data($admin->company_id));
        $route = TripRoute::query()->firstOrFail();

        $data = $this->data($admin->company_id);
        $data['budgets'] = array_slice($data['budgets'], 0, 1, true);
        $this->patch("/office/routes/{$route->id}", $data)->assertSessionHasNoErrors();

        $this->assertCount(1, $route->fresh()->budgets);
        $this->assertTrue(AuditLog::query()->where('action', 'route.updated')->exists());
    }

    public function test_a_route_with_customer_prices_cannot_be_deleted(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $this->actingAs($admin, 'web')->post('/office/routes', $this->data($company->id));
        $route = TripRoute::query()->firstOrFail();
        RateCard::query()->create(['company_id' => $company->id, 'customer_id' => Customer::factory()->for($company)->create()->id, 'trip_route_id' => $route->id, 'price' => 10000]);

        $this->delete("/office/routes/{$route->id}")->assertSessionHas('error');
        $this->assertNotNull($route->fresh());
    }

    public function test_routes_follow_the_customers_permission(): void
    {
        $company = $this->company();

        $this->actingAs($this->officeUser($company, ['customers.view']), 'web')->get('/office/routes')->assertOk();
        $this->post('/office/routes', $this->data($company->id))->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->officeUser($company, ['trips.view']), 'web')->get('/office/routes')->assertForbidden();
    }

    public function test_a_route_of_another_company_is_not_found(): void
    {
        $admin = $this->companyAdmin();
        $foreign = TripRoute::factory()->for($this->company())->create();

        $this->actingAs($admin, 'web')->patch("/office/routes/{$foreign->id}", $this->data($admin->company_id))->assertNotFound();
        $this->delete("/office/routes/{$foreign->id}")->assertNotFound();
    }
}
