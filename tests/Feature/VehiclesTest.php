<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: vehicles (Step 2, Scope §6.7)
//  Location: tests/Feature/VehiclesTest.php
//  Feature doc: docs/STEP_02_MASTER_DATA.md §1
// ══════════════════════════════════════════════════════════════════

class VehiclesTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    /** The truck type "Tractor-Trailer" of a company (the standard types are created on demand). */
    private function typeId(int $companyId): int
    {
        CompanyDefaults::ensure($companyId);

        return VehicleType::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('code', 'tractor_trailer')->value('id');
    }

    private function data(int $companyId, array $overrides = []): array
    {
        return array_merge([
            'plate_number' => '٢١٤٠', 'plate_letters' => 'ن ق ل', 'vehicle_type_id' => $this->typeId($companyId), 'model' => 'Actros', 'year' => 2020,
            'ownership' => 'own', 'status' => 'available', 'std_km_per_litre' => 2.6,
            'licence_expires_at' => today()->addDays(10)->toDateString(),
            'insurance_expires_at' => today()->subDay()->toDateString(),
            'inspection_expires_at' => today()->addYear()->toDateString(),
        ], $overrides);
    }

    public function test_creating_a_vehicle_saves_it_with_western_digits_and_is_audited(): void
    {
        $admin = $this->companyAdmin();

        $this->actingAs($admin, 'web')->post('/office/vehicles', $this->data($admin->company_id))->assertRedirect();

        $vehicle = Vehicle::query()->firstOrFail();
        $this->assertSame('2140', $vehicle->plate_number);
        $this->assertSame($admin->company_id, $vehicle->company_id);
        $this->assertTrue(AuditLog::query()->where('action', 'vehicle.created')->exists());
    }

    public function test_the_list_shows_document_states_and_the_expiry_alert(): void
    {
        $admin = $this->companyAdmin();
        $this->actingAs($admin, 'web')->post('/office/vehicles', $this->data($admin->company_id));

        $this->get('/office/vehicles')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Vehicles/Index')
            ->where('vehicles.data.0.documents.licence.state', 'soon')
            ->where('vehicles.data.0.documents.insurance.state', 'expired')
            ->where('vehicles.data.0.documents.inspection.state', 'ok')
            ->has('expiring', 2));
    }

    public function test_the_same_plate_twice_in_one_company_is_refused_but_another_company_may_use_it(): void
    {
        $admin = $this->companyAdmin();
        $this->actingAs($admin, 'web')->post('/office/vehicles', $this->data($admin->company_id));
        // (A different model, so it is not ignored as a double-click of the same form.)
        $this->post('/office/vehicles', $this->data($admin->company_id, ['model' => 'Axor']))->assertSessionHasErrors('plate_letters');

        $other = $this->companyAdmin();
        $this->flushSession();
        $this->actingAs($other, 'web')->post('/office/vehicles', $this->data($other->company_id))->assertSessionHasNoErrors();
        $this->assertSame(2, Vehicle::query()->withoutGlobalScopes()->count());
    }

    public function test_a_hired_truck_needs_its_owner(): void
    {
        $admin = $this->companyAdmin();
        $this->actingAs($admin, 'web')
            ->post('/office/vehicles', $this->data($admin->company_id, ['ownership' => 'hired']))->assertSessionHasErrors('owner_name');
    }

    public function test_a_driver_has_one_usual_vehicle(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $driver = $this->driver($company);
        $first = Vehicle::factory()->for($company)->create(['driver_id' => $driver->id]);

        $this->actingAs($admin, 'web')->post('/office/vehicles', $this->data($admin->company_id, ['driver_id' => $driver->id]))->assertSessionHasNoErrors();

        $this->assertNull($first->fresh()->driver_id);
        $this->assertSame($driver->id, Vehicle::query()->where('plate_number', '2140')->value('driver_id'));
    }

    public function test_permissions_are_enforced(): void
    {
        $company = $this->company();
        $viewer = $this->officeUser($company, ['vehicles.view']);
        $vehicle = Vehicle::factory()->for($company)->create();

        $this->actingAs($viewer, 'web')->get('/office/vehicles')->assertOk();
        $this->get("/office/vehicles/{$vehicle->id}")->assertOk();
        $this->post('/office/vehicles', $this->data($company->id))->assertForbidden();
        $this->patch("/office/vehicles/{$vehicle->id}", $this->data($company->id))->assertForbidden();
        $this->delete("/office/vehicles/{$vehicle->id}")->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->officeUser($company), 'web')->get('/office/vehicles')->assertForbidden();
    }

    public function test_a_vehicle_of_another_company_is_not_found_even_by_its_address(): void
    {
        $admin = $this->companyAdmin();
        $foreign = Vehicle::factory()->for($this->company())->create();

        $this->actingAs($admin, 'web')->get("/office/vehicles/{$foreign->id}")->assertNotFound();
        $this->patch("/office/vehicles/{$foreign->id}", $this->data($admin->company_id))->assertNotFound();
        $this->delete("/office/vehicles/{$foreign->id}")->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    public function test_a_driver_of_another_company_cannot_be_assigned(): void
    {
        $admin = $this->companyAdmin();
        $foreignDriver = $this->driver($this->company());

        $this->actingAs($admin, 'web')->post('/office/vehicles', $this->data($admin->company_id, ['driver_id' => $foreignDriver->id]))->assertSessionHasErrors('driver_id');
    }

    public function test_deleting_and_updating(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $vehicle = Vehicle::factory()->for($company)->create();

        $this->actingAs($admin, 'web')->patch("/office/vehicles/{$vehicle->id}", $this->data($company->id, ['status' => 'maintenance']))->assertSessionHasNoErrors();
        $this->assertSame('maintenance', $vehicle->fresh()->status);

        $this->delete("/office/vehicles/{$vehicle->id}")->assertRedirect('/office/vehicles');
        $this->assertNull($vehicle->fresh());
    }
}
