<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\InsuranceCompany;
use App\Models\RoutePlaceName;
use App\Models\TripRoute;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: insurance companies and route place names
//  Location: tests/Feature/NameListsTest.php
//  A name is added to the list at once, then a truck or a route may
//  use it. The same name twice in one company is refused. A name
//  that was never added cannot be saved.
// ══════════════════════════════════════════════════════════════════

class NameListsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_an_insurance_company_is_added_to_the_list_and_saved_on_the_truck(): void
    {
        $admin = $this->companyAdmin();
        $this->actingAs($admin, 'web');

        $this->postJson('/office/insurance-companies', ['name' => '  مصر للتأمين  '])
            ->assertCreated()
            ->assertJsonPath('name', 'مصر للتأمين');

        $this->get('/office/vehicles')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('options.insurers', fn ($names) => collect($names)->contains('مصر للتأمين')));

        $this->post('/office/vehicles', $this->vehicle($admin->company_id, ['insurance_company' => 'مصر للتأمين']))
            ->assertSessionHasNoErrors();
        $this->assertSame('مصر للتأمين', Vehicle::query()->firstOrFail()->insurance_company);

        $this->post('/office/vehicles', $this->vehicle($admin->company_id, ['plate_number' => '3001', 'insurance_company' => 'شركة مجهولة']))
            ->assertSessionHasErrors('insurance_company');
        $this->assertSame(1, Vehicle::query()->count());

        $this->actingAs($this->companyAdmin(), 'web')->get('/office/vehicles')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('options.insurers', []));
    }

    public function test_the_same_insurance_company_cannot_be_added_twice_and_needs_permission(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $this->actingAs($admin, 'web')->postJson('/office/insurance-companies', ['name' => 'مصر للتأمين'])->assertCreated();

        Cache::store(config('cache.durable'))->flush();
        $this->postJson('/office/insurance-companies', ['name' => 'مصر للتأمين'])->assertStatus(422);
        $this->assertSame(1, InsuranceCompany::query()->withoutGlobalScopes()->count());

        $this->actingAs($this->officeUser($company, ['vehicles.view']), 'web')
            ->postJson('/office/insurance-companies', ['name' => 'أخرى'])->assertForbidden();
    }

    public function test_a_place_added_in_arabic_can_be_the_origin_or_the_destination(): void
    {
        $admin = $this->companyAdmin();
        $this->actingAs($admin, 'web');

        $this->postJson('/office/route-places', ['language' => 'ar', 'name' => 'القاهرة'])->assertCreated()->assertJsonPath('name', 'القاهرة');
        $this->postJson('/office/route-places', ['language' => 'ar', 'name' => 'الإسكندرية'])->assertCreated();

        $this->get('/office/routes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('places.ar', function ($names) {
                $names = collect($names);

                return $names->contains('القاهرة') && $names->contains('الإسكندرية');
            }));

        CompanyDefaults::ensure($admin->company_id);
        $fuel = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $admin->company_id)->where('code', 'fuel')->value('id');

        $this->post('/office/routes', [
            'origin_ar' => 'القاهرة', 'destination_ar' => 'الإسكندرية', 'origin_en' => null, 'destination_en' => null,
            'weight_tons' => 5, 'km_round_trip' => 450, 'is_active' => true, 'budgets' => [$fuel => 100],
        ])->assertSessionHasNoErrors();

        $route = TripRoute::query()->firstOrFail();
        $this->assertSame('القاهرة', $route->origin_ar);
        $this->assertSame('الإسكندرية', $route->destination_ar);

        $this->post('/office/routes', [
            'origin_ar' => 'ليس في القائمة', 'destination_ar' => 'القاهرة',
            'weight_tons' => 5, 'km_round_trip' => 100, 'is_active' => true, 'budgets' => [],
        ])->assertSessionHasErrors('origin_ar');

        Cache::store(config('cache.durable'))->flush();
        $this->postJson('/office/route-places', ['language' => 'ar', 'name' => 'القاهرة'])->assertStatus(422);
        $this->assertSame(2, RoutePlaceName::query()->withoutGlobalScopes()->where('language', 'ar')->count());

        $this->actingAs($this->companyAdmin(), 'web')->get('/office/routes')->assertInertia(fn (Assert $page) => $page->where('places.ar', []));
    }

    public function test_an_english_place_is_kept_apart_from_the_arabic_list(): void
    {
        $admin = $this->companyAdmin();
        $this->actingAs($admin, 'web');

        $this->postJson('/office/route-places', ['language' => 'en', 'name' => 'Cairo'])->assertCreated();
        $this->postJson('/office/route-places', ['language' => 'en', 'name' => 'Alexandria'])->assertCreated();
        $this->postJson('/office/route-places', ['language' => 'ar', 'name' => 'القاهرة'])->assertCreated();
        $this->postJson('/office/route-places', ['language' => 'ar', 'name' => 'الإسكندرية'])->assertCreated();

        $this->get('/office/routes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('places.en', fn ($names) => collect($names)->contains('Cairo') && collect($names)->contains('Alexandria') && ! collect($names)->contains('القاهرة'))
            ->where('places.ar', fn ($names) => collect($names)->contains('القاهرة') && ! collect($names)->contains('Cairo')));

        CompanyDefaults::ensure($admin->company_id);
        $fuel = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $admin->company_id)->where('code', 'fuel')->value('id');

        $this->post('/office/routes', [
            'origin_ar' => 'القاهرة', 'destination_ar' => 'الإسكندرية', 'origin_en' => 'Cairo', 'destination_en' => 'Alexandria',
            'weight_tons' => 5, 'km_round_trip' => 220, 'is_active' => true, 'budgets' => [$fuel => 80],
        ])->assertSessionHasNoErrors();
        $route = TripRoute::query()->firstOrFail();
        $this->assertSame('القاهرة', $route->origin_ar);
        $this->assertSame('الإسكندرية', $route->destination_ar);
        $this->assertSame('Cairo', $route->origin_en);
        $this->assertSame('Alexandria', $route->destination_en);

        $this->post('/office/routes', [
            'origin_ar' => 'Cairo', 'destination_ar' => 'الإسكندرية',
            'weight_tons' => 5, 'km_round_trip' => 100, 'is_active' => true, 'budgets' => [],
        ])->assertSessionHasErrors('origin_ar');
        $this->post('/office/routes', [
            'origin_ar' => 'القاهرة', 'destination_ar' => 'الإسكندرية', 'origin_en' => 'القاهرة', 'destination_en' => 'Alexandria',
            'weight_tons' => 5, 'km_round_trip' => 100, 'is_active' => true, 'budgets' => [],
        ])->assertSessionHasErrors('origin_en');
    }

    /** @param array<string,mixed> $overrides */
    private function vehicle(int $companyId, array $overrides = []): array
    {
        CompanyDefaults::ensure($companyId);

        return array_merge([
            'plate_number' => '2140', 'plate_letters' => 'ن ق ل',
            'vehicle_type_id' => VehicleType::query()->withoutGlobalScopes()->where('company_id', $companyId)->value('id'),
            'ownership' => 'own', 'status' => 'available',
        ], $overrides);
    }
}
