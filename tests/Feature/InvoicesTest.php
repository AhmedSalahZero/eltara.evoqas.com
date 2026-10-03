<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Trip;
use App\Services\Trips\TripService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: invoice numbers on settled trips (Step 6, Scope §6.11)
//  Location: tests/Feature/InvoicesTest.php
//  Feature doc: docs/STEP_06_FUEL_FINANCE_MONTH_CLOSE.md
// ══════════════════════════════════════════════════════════════════

class InvoicesTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    /** A trip of the set-up customer, already settled (state set directly — settlement has its own tests). */
    private function settledTrip(?Customer $customer = null): Trip
    {
        $trip = Tenant::forCompany($this->co->id, function () use ($customer) {
            $data = $this->tripData($customer ? ['customer_id' => $customer->id, 'freight_price' => 9000] : []);

            return app(TripService::class)->create($data, $this->admin);
        });
        $trip->forceFill(['status' => 'settled', 'delivered_at' => now()->subDays(3), 'settled_at' => now()->subDays(2)])->save();

        return $trip->fresh();
    }

    public function test_one_invoice_number_can_cover_several_settled_trips(): void
    {
        $this->setUpFleet();
        $a = $this->settledTrip();
        $b = $this->settledTrip();

        $this->actingAs($this->admin, 'web')->post('/office/invoices', [
            'customer_id' => $this->customer->id, 'number' => 'INV-100', 'trip_ids' => [$a->id, $b->id],
        ])->assertSessionHas('success');

        $invoice = Invoice::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('INV-100', $invoice->number);
        $this->assertSame($invoice->id, (int) $a->fresh()->invoice_id);
        $this->assertSame($invoice->id, (int) $b->fresh()->invoice_id);
    }

    public function test_only_settled_trips_can_be_invoiced(): void
    {
        $this->setUpFleet();
        $planned = Tenant::forCompany($this->co->id, fn () => app(TripService::class)->create($this->tripData(), $this->admin));

        $this->actingAs($this->admin, 'web')->post('/office/invoices', [
            'customer_id' => $this->customer->id, 'number' => 'INV-101', 'trip_ids' => [$planned->id],
        ])->assertSessionHas('error');

        $this->assertSame(0, Invoice::query()->withoutGlobalScopes()->count());
        $this->assertNull($planned->fresh()->invoice_id);
    }

    public function test_a_trip_cannot_be_linked_to_two_invoices(): void
    {
        $this->setUpFleet();
        $trip = $this->settledTrip();
        $this->actingAs($this->admin, 'web')->post('/office/invoices', ['customer_id' => $this->customer->id, 'number' => 'INV-1', 'trip_ids' => [$trip->id]])->assertSessionHas('success');

        $this->post('/office/invoices', ['customer_id' => $this->customer->id, 'number' => 'INV-2', 'trip_ids' => [$trip->id]])->assertSessionHas('error');

        $this->assertSame(1, Invoice::query()->withoutGlobalScopes()->count());
    }

    public function test_trips_of_another_customer_cannot_go_on_the_invoice(): void
    {
        $this->setUpFleet();
        $other = Customer::factory()->for($this->co)->create(['name_en' => 'Other Co']);
        $otherTrip = $this->settledTrip($other);

        $this->actingAs($this->admin, 'web')->post('/office/invoices', [
            'customer_id' => $this->customer->id, 'number' => 'INV-5', 'trip_ids' => [$otherTrip->id],
        ])->assertSessionHas('error');

        $this->assertNull($otherTrip->fresh()->invoice_id);
    }

    public function test_using_an_existing_number_adds_trips_to_the_same_invoice(): void
    {
        $this->setUpFleet();
        $a = $this->settledTrip();
        $b = $this->settledTrip();
        $this->actingAs($this->admin, 'web')->post('/office/invoices', ['customer_id' => $this->customer->id, 'number' => 'INV-7', 'trip_ids' => [$a->id]]);

        $this->post('/office/invoices', ['customer_id' => $this->customer->id, 'number' => 'INV-7', 'trip_ids' => [$b->id]])->assertSessionHas('success');

        $this->assertSame(1, Invoice::query()->withoutGlobalScopes()->count());
        $this->assertSame($a->fresh()->invoice_id, $b->fresh()->invoice_id);
    }

    public function test_removing_the_invoice_frees_its_trips(): void
    {
        $this->setUpFleet();
        $trip = $this->settledTrip();
        $this->actingAs($this->admin, 'web')->post('/office/invoices', ['customer_id' => $this->customer->id, 'number' => 'INV-9', 'trip_ids' => [$trip->id]]);
        $invoice = Invoice::query()->withoutGlobalScopes()->firstOrFail();

        $this->delete("/office/invoices/{$invoice->id}")->assertSessionHas('success');

        $this->assertSame(0, Invoice::query()->withoutGlobalScopes()->count());
        $this->assertNull($trip->fresh()->invoice_id);
    }

    public function test_the_trip_list_can_be_searched_by_invoice_number(): void
    {
        $this->setUpFleet();
        $a = $this->settledTrip();
        $this->settledTrip();
        $this->actingAs($this->admin, 'web')->post('/office/invoices', ['customer_id' => $this->customer->id, 'number' => 'INV-FIND-ME', 'trip_ids' => [$a->id]]);

        $this->get('/office/trips?search=FIND-ME')->assertOk()->assertInertia(fn ($page) => $page
            ->has('trips.data', 1)
            ->where('trips.data.0.invoice', 'INV-FIND-ME'));
    }
}
