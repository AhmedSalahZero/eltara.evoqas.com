<?php

namespace Tests\Feature;

use App\Models\ClientUser;
use App\Models\Customer;
use App\Models\Driver;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: every company sees only its own data
//  Location: tests/Feature/TenantIsolationTest.php
//  Feature doc: docs/ARCHITECTURE.md "One database, many companies"
// ══════════════════════════════════════════════════════════════════

class TenantIsolationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_company_data_is_filtered_to_the_signed_in_company(): void
    {
        $a = $this->company();
        $b = $this->company();
        $this->driver($a);
        $this->driver($b, ['mobile' => '01209999999']);
        Customer::factory()->for($a)->create();
        Customer::factory()->for($b)->create();

        Tenant::set($a->id);

        $this->assertSame([$a->id], Driver::query()->pluck('company_id')->unique()->values()->all());
        $this->assertSame([$a->id], Customer::query()->pluck('company_id')->unique()->values()->all());
    }

    public function test_new_records_are_stamped_with_the_current_company(): void
    {
        $a = $this->company();
        Tenant::set($a->id);

        $customer = Customer::query()->create(['name_ar' => 'عميل', 'name_en' => 'Client']);
        $this->assertSame($a->id, $customer->company_id);
    }

    public function test_without_a_company_context_nothing_is_visible_to_a_request(): void
    {
        $this->driver();
        Tenant::set(Tenant::ORPHAN);

        $this->assertSame(0, Driver::query()->count());
        $this->assertSame(0, ClientUser::query()->count());
    }

    public function test_the_office_page_sets_the_company_of_the_signed_in_user(): void
    {
        $user = $this->officeUser();

        $this->actingAs($user, 'web')->get('/office')->assertOk();
        $this->assertSame($user->company_id, Tenant::id());
    }
}
