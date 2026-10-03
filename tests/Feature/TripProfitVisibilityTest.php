<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: who may see the profit of a trip
//  Location: tests/Feature/TripProfitVisibilityTest.php
//  Being allowed to VIEW trips is not enough to see what the company
//  earns on them: that needs its own permission, trips.see_profit.
//  Without it the figures are not even sent to the browser.
// ══════════════════════════════════════════════════════════════════

class TripProfitVisibilityTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    public function test_a_user_who_may_only_view_trips_gets_no_profit_figures(): void
    {
        $trip = $this->runningTrip();
        $viewer = $this->officeUser($this->co, ['trips.view']);

        $this->actingAs($viewer, 'web')->get('/office/trips')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.profit', null)
            ->where('trips.data.0.profit', null)
            ->where('trips.data.0.margin', null)
            ->has('totals.revenue'));

        $this->get("/office/trips/{$trip->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('figures.profit', null)
            ->where('figures.margin', null)
            ->where('figures.profit_per_km', null)
            ->where('figures.true_profit', null)
            ->where('figures.ga_parts', [])
            ->has('figures.revenue'));
    }

    public function test_the_see_profit_permission_and_the_company_admin_get_the_figures(): void
    {
        $trip = $this->runningTrip();
        $analyst = $this->officeUser($this->co, ['trips.view', 'trips.see_profit']);

        foreach ([$analyst, $this->admin] as $user) {
            $this->actingAs($user, 'web')->get('/office/trips')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->whereNot('totals.profit', null)
                ->whereNot('trips.data.0.profit', null));

            $this->get("/office/trips/{$trip->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->whereNot('figures.profit', null));
        }
    }

    public function test_the_excel_export_leaves_out_the_profit_columns_without_the_permission(): void
    {
        $this->runningTrip();
        $viewer = $this->officeUser($this->co, ['trips.view']);

        $file = function ($user) {
            $response = $this->actingAs($user, 'web')->get('/office/trips/export');
            $response->assertOk();
            $path = tempnam(sys_get_temp_dir(), 'trips').'.xlsx';
            file_put_contents($path, $response->streamedContent());
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
            @unlink($path);

            return $sheet->getHighestColumn();
        };

        $this->assertSame('M', $file($viewer));
        $this->assertSame('O', $file($this->admin));
    }

    public function test_the_permission_exists_only_on_trips(): void
    {
        $this->assertTrue(\App\Support\Permissions::exists('trips.see_profit'));
        $this->assertFalse(\App\Support\Permissions::exists('fuel.see_profit'));
        $this->assertCount(16, config('permissions.features'));
    }
}
