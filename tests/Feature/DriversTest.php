<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: drivers (Step 2, Scope §6.8)
//  Location: tests/Feature/DriversTest.php
//  Feature doc: docs/STEP_02_MASTER_DATA.md §2
// ══════════════════════════════════════════════════════════════════

class DriversTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function data(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sayed Fathy', 'mobile' => '+20 100 555 0101', 'pin' => '', 'pay_basis' => 'fixed_plus_trip',
            'license_expires_at' => today()->addYear()->toDateString(), 'base_salary' => 7000,
        ], $overrides);
    }

    public function test_a_new_driver_gets_a_pin_shown_once_and_can_sign_in_with_it(): void
    {
        $admin = $this->companyAdmin();

        $response = $this->actingAs($admin, 'web')->post('/office/drivers', $this->data());
        $response->assertRedirect()->assertSessionHas('pin');

        $pin = session('pin')['pin'];
        $driver = Driver::query()->where('mobile', '01005550101')->firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);
        $this->assertTrue(Hash::check($pin, $driver->pin));

        $this->flushSession();
        $this->postJson('/driver/api/login', ['mobile' => '01005550101', 'pin' => $pin])->assertOk();
    }

    public function test_the_office_can_choose_the_first_pin(): void
    {
        $this->actingAs($this->companyAdmin(), 'web')->post('/office/drivers', $this->data(['pin' => '٤٤٥٥']));

        $this->assertSame('4455', session('pin')['pin']);
    }

    public function test_the_driver_accounts_limit_is_enforced(): void
    {
        $company = $this->company(['driver_accounts_limit' => 1]);
        $admin = $this->companyAdmin($company);
        $this->driver($company);

        $this->actingAs($admin, 'web')->post('/office/drivers', $this->data())->assertSessionHas('error');
        $this->assertSame(1, Driver::query()->withoutGlobalScopes()->count());
    }

    public function test_a_mobile_can_open_only_one_driver_account_across_all_companies(): void
    {
        $this->driver($this->company(), ['mobile' => '01005550101']);

        $this->actingAs($this->companyAdmin(), 'web')->post('/office/drivers', $this->data())->assertSessionHasErrors('mobile');
    }

    public function test_resetting_the_pin_stops_the_old_one(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $driver = $this->driver($company, ['mobile' => '01005550101']);

        $this->actingAs($admin, 'web')->post("/office/drivers/{$driver->id}/pin")->assertSessionHas('pin');
        $new = session('pin')['pin'];

        $this->assertFalse(Hash::check('1234', $driver->fresh()->pin) && $new !== '1234');
        $this->assertTrue(Hash::check($new, $driver->fresh()->pin));
    }

    public function test_suspending_frees_a_place_and_reactivating_needs_one(): void
    {
        $company = $this->company(['driver_accounts_limit' => 1]);
        $admin = $this->companyAdmin($company);
        $driver = $this->driver($company);

        $this->actingAs($admin, 'web')->post("/office/drivers/{$driver->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($driver->fresh()->is_active);

        $this->post('/office/drivers', $this->data())->assertSessionHas('pin');
        $this->post("/office/drivers/{$driver->id}/toggle")->assertSessionHas('error');
        $this->assertFalse($driver->fresh()->is_active);
    }

    public function test_the_usual_vehicle_is_linked_both_ways(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $vehicle = Vehicle::factory()->for($company)->create();

        $this->actingAs($admin, 'web')->post('/office/drivers', $this->data(['vehicle_id' => $vehicle->id]));
        $driver = Driver::query()->where('mobile', '01005550101')->firstOrFail();

        $this->assertSame($driver->id, $vehicle->fresh()->driver_id);

        $this->patch("/office/drivers/{$driver->id}", $this->data(['vehicle_id' => null, 'pin' => null]))->assertSessionHasNoErrors();
        $this->assertNull($vehicle->fresh()->driver_id);
    }

    public function test_drivers_of_another_company_are_not_found(): void
    {
        $admin = $this->companyAdmin();
        $foreign = $this->driver($this->company());

        $this->actingAs($admin, 'web')->get("/office/drivers/{$foreign->id}")->assertNotFound();
        $this->post("/office/drivers/{$foreign->id}/pin")->assertNotFound();
        $this->post("/office/drivers/{$foreign->id}/toggle")->assertNotFound();
        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_the_list_and_file_open_for_a_viewer_but_changes_need_permission(): void
    {
        $company = $this->company();
        $viewer = $this->officeUser($company, ['drivers.view']);
        $driver = $this->driver($company);

        $this->actingAs($viewer, 'web')->get('/office/drivers')->assertOk();
        $this->get("/office/drivers/{$driver->id}")->assertOk();
        $this->post('/office/drivers', $this->data())->assertForbidden();
        $this->post("/office/drivers/{$driver->id}/pin")->assertForbidden();
    }
}
