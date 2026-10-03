<?php

namespace Tests\Feature;

use App\Models\DriverAdvance;
use App\Models\DriverAdvanceRepayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: driver advances, instalments and payroll (Step 6, Scope §6.12)
//  Location: tests/Feature/AdvancesTest.php
//  Feature doc: docs/STEP_06_FUEL_FINANCE_MONTH_CLOSE.md
// ══════════════════════════════════════════════════════════════════

class AdvancesTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    private function give(float $amount, ?float $instalment = null): DriverAdvance
    {
        $this->actingAs($this->admin, 'web')->post('/office/advances', [
            'driver_id' => $this->drv->id, 'amount' => $amount, 'monthly_instalment' => $instalment, 'reason' => 'Test',
        ])->assertSessionHas('success');

        return DriverAdvance::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_the_office_can_give_an_advance(): void
    {
        $this->setUpFleet();

        $advance = $this->give(3000, 1000);

        $this->assertSame('open', $advance->status);
        $this->assertSame('manual', $advance->source);
        $this->assertEquals(3000.0, (float) $advance->amount);
        $this->assertEquals(0.0, (float) $advance->repaid_amount);
    }

    public function test_payroll_takes_one_instalment_and_only_once_a_month(): void
    {
        $this->setUpFleet();
        $advance = $this->give(3000, 1000);
        $month = now()->format('Y-m');

        $this->post('/office/advances/payroll', ['month' => $month])->assertSessionHas('success');
        $this->assertEquals(1000.0, (float) $advance->fresh()->repaid_amount);

        // Pressing the button again in the same month changes nothing.
        $this->post('/office/advances/payroll', ['month' => $month])->assertSessionHas('error');
        $this->assertEquals(1000.0, (float) $advance->fresh()->repaid_amount);
        $this->assertSame(1, DriverAdvanceRepayment::query()->withoutGlobalScopes()->count());
    }

    public function test_an_advance_without_an_instalment_is_taken_in_full(): void
    {
        $this->setUpFleet();
        $advance = $this->give(1500, null);

        $this->post('/office/advances/payroll', ['month' => now()->format('Y-m')])->assertSessionHas('success');

        $advance->refresh();
        $this->assertEquals(1500.0, (float) $advance->repaid_amount);
        $this->assertSame('repaid', $advance->status);
    }

    public function test_cash_repayment_cannot_be_more_than_what_is_left(): void
    {
        $this->setUpFleet();
        $advance = $this->give(1000, null);

        $this->post("/office/advances/{$advance->id}/repay", ['amount' => 400])->assertSessionHas('success');
        $this->post("/office/advances/{$advance->id}/repay", ['amount' => 700])->assertSessionHas('error');

        $this->assertEquals(400.0, (float) $advance->fresh()->repaid_amount);
    }

    public function test_only_an_untouched_manual_advance_can_be_cancelled(): void
    {
        $this->setUpFleet();
        $untouched = $this->give(1000, null);
        $touched = $this->give(2000, null);
        $this->post("/office/advances/{$touched->id}/repay", ['amount' => 100])->assertSessionHas('success');

        $this->post("/office/advances/{$touched->id}/cancel")->assertSessionHas('error');
        $this->post("/office/advances/{$untouched->id}/cancel")->assertSessionHas('success');

        $this->assertSame('open', $touched->fresh()->status);
        $this->assertSame('cancelled', $untouched->fresh()->status);
    }

    public function test_a_user_without_the_permission_cannot_give_or_deduct(): void
    {
        $this->setUpFleet();
        $viewer = $this->officeUser($this->co, ['driver_advances.view']);

        $this->actingAs($viewer, 'web')->get('/office/advances')->assertOk();
        $this->post('/office/advances', ['driver_id' => $this->drv->id, 'amount' => 100])->assertForbidden();
        $this->post('/office/advances/payroll', ['month' => now()->format('Y-m')])->assertForbidden();
    }
}
