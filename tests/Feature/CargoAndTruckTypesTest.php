<?php

namespace Tests\Feature;

use App\Models\CargoType;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: cargo (goods) + weight on trips, and truck types
//  Location: tests/Feature/CargoAndTruckTypesTest.php
//  Feature doc: docs/STEP_03_TRIPS_AND_WALLETS.md (Cargo & truck types)
// ══════════════════════════════════════════════════════════════════

class CargoAndTruckTypesTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    public function test_every_company_gets_the_twelve_standard_truck_types(): void
    {
        $company = $this->company();
        CompanyDefaults::ensure($company->id);
        CompanyDefaults::ensure($company->id); // twice: nothing is created twice

        $types = VehicleType::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();
        $this->assertCount(12, $types);
        $this->assertSame('Heavy Truck', $types->firstWhere('code', 'heavy')->name_en);
        $this->assertSame('ثلاجة', $types->firstWhere('code', 'refrigerated')->name_ar);
    }

    public function test_a_new_truck_type_can_be_added_from_the_truck_form_and_used(): void
    {
        $admin = $this->companyAdmin();
        CompanyDefaults::ensure($admin->company_id);

        $response = $this->actingAs($admin, 'web')->postJson('/office/vehicle-types', ['name_ar' => 'سوستة', 'name_en' => 'Flatbed']);
        $response->assertCreated();
        $id = $response->json('id');

        $this->post('/office/vehicles', [
            'plate_number' => '1234', 'plate_letters' => 'ن ق ل', 'vehicle_type_id' => $id,
            'ownership' => 'own', 'status' => 'available',
        ])->assertSessionHasNoErrors();

        $this->assertSame($id, Vehicle::query()->value('vehicle_type_id'));
        $this->get('/office/vehicles')->assertInertia(fn (Assert $page) => $page->has('options.types', 13));
    }

    public function test_a_duplicate_name_is_refused_but_another_company_may_use_it(): void
    {
        $admin = $this->companyAdmin();
        CompanyDefaults::ensure($admin->company_id);

        $this->actingAs($admin, 'web')->postJson('/office/vehicle-types', ['name_ar' => 'قلاب'])->assertStatus(422)->assertJsonValidationErrors('name_ar');

        $other = $this->companyAdmin();
        CompanyDefaults::ensure($other->company_id);
        $this->flushSession();
        $this->actingAs($other, 'web')->postJson('/office/vehicle-types', ['name_ar' => 'سوستة'])->assertCreated();
        $this->flushSession();
        $this->actingAs($admin, 'web')->postJson('/office/vehicle-types', ['name_ar' => 'سوستة'])->assertCreated();
    }

    public function test_a_truck_cannot_use_a_type_of_another_company(): void
    {
        $admin = $this->companyAdmin();
        $foreign = $this->companyAdmin();
        CompanyDefaults::ensure($foreign->company_id);
        $foreignType = VehicleType::query()->withoutGlobalScopes()->where('company_id', $foreign->company_id)->value('id');

        $this->actingAs($admin, 'web')->post('/office/vehicles', [
            'plate_number' => '1234', 'plate_letters' => 'ن ق ل', 'vehicle_type_id' => $foreignType, 'ownership' => 'own', 'status' => 'available',
        ])->assertSessionHasErrors('vehicle_type_id');
    }

    public function test_adding_types_needs_permission_and_renaming_needs_settings_edit(): void
    {
        $company = $this->company();
        CompanyDefaults::ensure($company->id);
        $viewer = $this->officeUser($company, ['vehicles.view', 'trips.view']);
        $type = VehicleType::query()->withoutGlobalScopes()->where('company_id', $company->id)->first();

        $this->actingAs($viewer, 'web')->postJson('/office/vehicle-types', ['name_ar' => 'جديد'])->assertForbidden();
        $this->postJson('/office/cargo-types', ['name_ar' => 'جديد'])->assertForbidden();
        $this->patch("/office/vehicle-types/{$type->id}", ['name_ar' => 'اسم آخر'])->assertForbidden();

        $admin = $this->companyAdmin($company);
        $this->flushSession();
        $this->actingAs($admin, 'web')->patch("/office/vehicle-types/{$type->id}", ['name_ar' => 'اسم آخر', 'name_en' => 'Other name', 'is_active' => 0])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertFalse($type->fresh()->is_active);
        $this->assertSame('اسم آخر', $type->fresh()->name_ar);
    }

    public function test_a_cargo_type_can_be_renamed_and_hidden_and_a_hidden_one_leaves_the_trip_form(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');
        $id = $this->postJson('/office/cargo-types', ['name_ar' => 'أسمنت'])->assertCreated()->json('id');

        $this->patch("/office/cargo-types/{$id}", ['name_ar' => 'أسمنت معبأ', 'name_en' => 'Bagged cement', 'is_active' => 0])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $cargo = CargoType::query()->findOrFail($id);
        $this->assertSame('أسمنت معبأ', $cargo->name_ar);
        $this->assertFalse($cargo->is_active);
        $this->get('/office/trips')->assertInertia(fn (Assert $page) => $page->where('options.cargo_types', fn ($list) => collect($list)->where('id', $id)->isEmpty()));
    }

    public function test_a_trip_keeps_its_cargo_and_its_weight_apart(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $cargo = $this->postJson('/office/cargo-types', ['name_ar' => 'أسمنت', 'name_en' => 'Cement'])->assertCreated()->json('id');

        $this->post('/office/trips', $this->tripData(['cargo_type_id' => $cargo, 'weight_tons' => 27.5]))->assertSessionHasNoErrors();

        $trip = Trip::query()->latest('id')->firstOrFail();
        $this->assertSame($cargo, $trip->cargo_type_id);
        $this->assertSame(27.5, $trip->weight_tons);

        $this->patch("/office/trips/{$trip->id}", ['weight_tons' => 30])->assertSessionHasNoErrors();
        $this->assertSame(30.0, $trip->fresh()->weight_tons);

        $this->get("/office/trips/{$trip->id}")->assertInertia(fn (Assert $page) => $page
            ->where('trip.cargo_type_id', $cargo)->where('trip.weight_tons', 30)->has('trip.cargo'));
    }

    public function test_cargo_and_weight_are_optional_and_checked(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $this->post('/office/trips', $this->tripData(['cargo_type_id' => null, 'weight_tons' => null]))->assertSessionHasNoErrors();
        $this->post('/office/trips', $this->tripData(['weight_tons' => -5]))->assertSessionHasErrors('weight_tons');

        $foreign = CargoType::query()->withoutGlobalScopes()->create(['company_id' => $this->company()->id, 'name_ar' => 'جبن']);
        $this->post('/office/trips', $this->tripData(['cargo_type_id' => $foreign->id]))->assertSessionHasErrors('cargo_type_id');
    }
}
