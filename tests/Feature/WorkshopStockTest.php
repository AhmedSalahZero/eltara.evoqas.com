<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\GaEntry;
use App\Models\MaintenanceJob;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\Store;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\WalletEntry;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the workshop store and maintenance on trips
//  Location: tests/Feature/WorkshopStockTest.php
//  A receipt raises quantity and value. An issue cannot exceed what
//  is there. The issued cost, or an outside bill, is shared onto
//  the truck's later trips and is not written into month-close G&A.
// ══════════════════════════════════════════════════════════════════

class WorkshopStockTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    public function test_a_receipt_sets_the_average_and_a_second_receipt_updates_it(): void
    {
        $this->setUpFleet();
        $this->turnWorkshopOn();
        $this->actingAs($this->admin, 'web');

        $store = Store::query()->where('is_default', true)->firstOrFail();
        $this->post('/office/stock/items', ['name' => 'كاوتش', 'unit' => 'piece'])->assertSessionHas('success');
        $item = StockItem::query()->where('name', 'كاوتش')->firstOrFail();

        $this->post('/office/stock/receipts', [
            'store_id' => $store->id, 'stock_item_id' => $item->id, 'qty' => 10, 'unit_price' => 100, 'supplier' => 'شركة الإطارات',
        ])->assertSessionHas('success');
        $this->post('/office/stock/receipts', [
            'store_id' => $store->id, 'stock_item_id' => $item->id, 'qty' => 10, 'unit_price' => 200,
        ])->assertSessionHas('success');

        $balance = StockBalance::query()->where('stock_item_id', $item->id)->firstOrFail();
        $this->assertEquals(20.0, (float) $balance->qty);
        $this->assertEquals(3000.0, (float) $balance->value);
        $this->assertEquals(150.0, $balance->average());
    }

    public function test_the_workshop_issues_stock_and_the_next_trip_carries_the_share(): void
    {
        $this->setUpFleet();
        $this->turnWorkshopOn();
        $this->actingAs($this->admin, 'web');

        $store = Store::query()->where('is_default', true)->firstOrFail();
        $this->post('/office/stock/items', ['name' => 'زيت', 'unit' => 'litre']);
        $item = StockItem::query()->where('name', 'زيت')->firstOrFail();
        $this->post('/office/stock/receipts', [
            'store_id' => $store->id, 'stock_item_id' => $item->id, 'qty' => 20, 'unit_price' => 50,
        ]);

        $this->post('/office/maintenance', $this->job([
            'source' => 'own', 'store_id' => $store->id, 'cost' => null, 'interval_km' => 10000,
            'lines' => [['item_id' => $item->id, 'qty' => 4]],
        ]))->assertSessionHas('success');

        $balance = StockBalance::query()->where('stock_item_id', $item->id)->firstOrFail();
        $this->assertEquals(16.0, (float) $balance->qty);
        $this->assertEquals(800.0, (float) $balance->value);

        $job = MaintenanceJob::query()->firstOrFail();
        $this->assertEquals(200.0, (float) $job->cost);

        $this->post('/office/trips', $this->tripData())->assertSessionHasNoErrors();
        $share = TripExpense::query()->where('maintenance_job_id', $job->id)->firstOrFail();
        // 450 km × (200 ÷ 10000) = 9
        $this->assertEquals(9.0, (float) $share->amount);
        $this->assertSame('company', $share->paid_from);
        $this->assertSame(0, GaEntry::query()->withoutGlobalScopes()->count());

        $this->post('/office/maintenance', $this->job([
            'source' => 'own', 'store_id' => $store->id, 'category' => 'filters', 'cost' => null,
            'lines' => [['item_id' => $item->id, 'qty' => 1000]],
        ]))->assertSessionHas('error');
        $this->assertEquals(16.0, (float) $balance->fresh()->qty);
    }

    public function test_an_outside_bill_is_spread_by_kilometres_and_a_breakdown_lands_on_the_next_trip(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        // Both intervals are set. Kilometres win: 450 × (1000 ÷ 4500) = 100, not a one-day share.
        $this->post('/office/maintenance', $this->job(['cost' => 1000, 'interval_km' => 4500, 'interval_days' => 1]))->assertSessionHas('success');
        $this->post('/office/trips', $this->tripData())->assertSessionHasNoErrors();
        $first = TripExpense::query()->whereNotNull('maintenance_job_id')->firstOrFail();
        // 450 × (1000 ÷ 4500) = 100
        $this->assertEquals(100.0, (float) $first->amount);

        $this->post('/office/maintenance', $this->job([
            'kind' => 'emergency', 'category' => 'other', 'note' => 'Gearbox', 'cost' => 300,
            'interval_km' => null, 'interval_days' => null,
        ]))->assertSessionHas('success');

        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addDays(2)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();
        $breakdown = MaintenanceJob::query()->where('kind', 'emergency')->firstOrFail();
        $whole = TripExpense::query()->where('maintenance_job_id', $breakdown->id)->get();
        $earliest = Trip::query()->orderBy('loading_at')->orderBy('id')->firstOrFail();
        $this->assertCount(1, $whole);
        $this->assertEquals(300.0, (float) $whole[0]->amount);
        $this->assertSame($earliest->id, $whole[0]->trip_id);
        $this->assertSame(0, GaEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_a_day_interval_shares_the_trips_days_and_the_pool_stops_when_it_is_spent(): void
    {
        $this->setUpFleet();
        $this->route->update(['usual_hours' => 24]);
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->job([
            'cost' => 1000, 'interval_km' => null, 'interval_days' => 10, 'odometer_km' => null,
        ]))->assertSessionHas('success');

        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addHours(3)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();
        $dayJob = MaintenanceJob::query()->firstOrFail();
        // 24 hours = 1 day, of a 10-day interval: 1000 × 1 ÷ 10 = 100
        $this->assertEquals(100.0, (float) TripExpense::query()->where('maintenance_job_id', $dayJob->id)->value('amount'));

        $this->post('/office/maintenance', $this->job([
            'category' => 'tyres', 'cost' => 100, 'interval_km' => 450, 'interval_days' => null,
            'completed_at' => now()->subMinutes(30)->format('Y-m-d H:i'),
        ]))->assertSessionHas('success');
        $tyres = MaintenanceJob::query()->where('category', 'tyres')->firstOrFail();
        // The trip already exists and uses the whole 450 km, so it takes the whole 100.
        $this->assertEquals(100.0, (float) TripExpense::query()->where('maintenance_job_id', $tyres->id)->value('amount'));

        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addDays(3)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();
        $this->assertSame(1, TripExpense::query()->where('maintenance_job_id', $tyres->id)->count());
        $this->assertSame(0, WalletEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, GaEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_a_settled_trip_keeps_its_share_and_an_old_job_is_not_spread(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->job(['cost' => 1000, 'interval_km' => 4500]))->assertSessionHas('success');
        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addHours(4)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();
        $job = MaintenanceJob::query()->firstOrFail();
        $trip = Trip::query()->firstOrFail();
        $expense = TripExpense::query()->where('maintenance_job_id', $job->id)->firstOrFail();
        $this->assertEquals(100.0, (float) $expense->amount);

        $trip->forceFill(['status' => 'settled', 'settled_at' => now()])->save();
        $this->patch("/office/maintenance/{$job->id}", $this->job(['cost' => 2000, 'interval_km' => 4500]))->assertSessionHas('success');
        $this->assertEquals(100.0, (float) $expense->fresh()->amount);

        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addDays(4)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();
        $open = TripExpense::query()->where('maintenance_job_id', $job->id)->where('trip_id', '!=', $trip->id)->firstOrFail();
        // What is left is 1,900, and this trip's share at the new rate is 450 × (2000 ÷ 4500) = 200.
        $this->assertEquals(200.0, (float) $open->amount);

        MaintenanceJob::query()->create([
            'company_id' => $this->co->id, 'vehicle_id' => $this->truck->id, 'kind' => 'emergency', 'category' => 'other',
            'status' => 'done', 'source' => 'external', 'shares_trips' => false, 'cost' => 500,
            'opened_at' => now()->subDays(2), 'completed_at' => now()->subDays(2), 'note' => 'Old',
        ]);
        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addDays(5)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();
        $this->assertSame(0, TripExpense::query()->where('amount', 500)->count());
    }

    public function test_deleting_a_workshop_job_puts_the_parts_back_and_the_report_shows_what_is_left(): void
    {
        $this->setUpFleet();
        $this->turnWorkshopOn();
        $this->actingAs($this->admin, 'web');
        $store = Store::query()->where('is_default', true)->firstOrFail();
        $this->post('/office/stock/items', ['name' => 'فلتر', 'unit' => 'piece']);
        $item = StockItem::query()->where('name', 'فلتر')->firstOrFail();
        $this->post('/office/stock/receipts', [
            'store_id' => $store->id, 'stock_item_id' => $item->id, 'qty' => 10, 'unit_price' => 1.01,
        ]);

        $this->post('/office/maintenance', $this->job([
            'source' => 'own', 'store_id' => $store->id, 'category' => 'filters', 'cost' => null, 'interval_km' => 900,
            'lines' => [['item_id' => $item->id, 'qty' => 3], ['item_id' => $item->id, 'qty' => 7]],
        ]))->assertSessionHas('success');

        $balance = StockBalance::query()->where('stock_item_id', $item->id)->firstOrFail();
        $this->assertEquals(0.0, (float) $balance->qty);
        $this->assertEquals(0.0, (float) $balance->value);
        $job = MaintenanceJob::query()->firstOrFail();
        // 10 × 1.01 = 10.10. The first 3 take 3.03; the last 7 take the 7.07 left.
        $this->assertEquals(10.10, (float) $job->cost);

        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addHours(6)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();
        // 450 km × (10.10 ÷ 900) = 5.05, so 5.05 is still on the truck
        $this->get('/office/reports/maintenance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.rows.0.allocated', fn ($v) => abs((float) $v - 5.05) < 0.01)
            ->where('report.rows.0.remaining', fn ($v) => abs((float) $v - 5.05) < 0.01));

        $this->delete("/office/maintenance/{$job->id}")->assertSessionHas('success');
        $this->assertEquals(10.0, (float) $balance->fresh()->qty);
        $this->assertEquals(10.10, (float) $balance->fresh()->value);
        $this->assertSame(0, TripExpense::query()->where('maintenance_job_id', $job->id)->count());
    }

    public function test_turning_the_workshop_on_creates_its_store_and_the_page_needs_permission(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->admin, 'web');

        $this->put('/office/settings', [
            'default_transfer_policy' => 'limit', 'auto_transfer_limit' => 1000, 'custody_buffer_percent' => 5,
            'personal_spend_to_advance' => true, 'has_workshop' => true, 'month_split_rule' => 'hours', 'ga_basis' => 'own_km',
            'receipt_photo_required' => true, 'capture_location' => true, 'offline_mode' => true, 'max_hours_without_sync' => 12,
            'diesel_price' => 20.50, 'currency' => 'EGP', 'default_language' => 'ar', 'default_theme' => 'dark',
        ])->assertSessionHasNoErrors();

        $store = Store::query()->where('is_default', true)->firstOrFail();
        $this->assertSame('ورشة الشركة', $store->name);

        $this->get('/office/stock')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Office/Stock/Index')->where('stores.0.name', 'ورشة الشركة'));

        $this->actingAs($this->officeUser($this->co, ['trips.view']), 'web')->get('/office/stock')->assertForbidden();

        $this->actingAs($this->companyAdmin(), 'web')->get('/office/stock')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stores', [])->where('items', []));
    }

    public function test_editing_an_issued_job_does_not_issue_again_and_reopening_it_puts_the_parts_back(): void
    {
        $this->setUpFleet();
        $this->turnWorkshopOn();
        $this->actingAs($this->admin, 'web');
        $store = Store::query()->where('is_default', true)->firstOrFail();
        $this->post('/office/stock/items', ['name' => 'زيت', 'unit' => 'litre']);
        $item = StockItem::query()->where('name', 'زيت')->firstOrFail();
        $this->post('/office/stock/receipts', [
            'store_id' => $store->id, 'stock_item_id' => $item->id, 'qty' => 20, 'unit_price' => 50,
        ]);
        $this->post('/office/maintenance', $this->job([
            'source' => 'own', 'store_id' => $store->id, 'cost' => null, 'interval_km' => 10000,
            'lines' => [['item_id' => $item->id, 'qty' => 4]],
        ]))->assertSessionHas('success');
        $this->post('/office/trips', $this->tripData(['loading_at' => now()->addHours(8)->format('Y-m-d H:i')]))->assertSessionHasNoErrors();

        $job = MaintenanceJob::query()->firstOrFail();
        $this->patch("/office/maintenance/{$job->id}", $this->job([
            'source' => 'own', 'store_id' => $store->id, 'cost' => 9999, 'note' => 'Adjusted',
            'lines' => [['item_id' => $item->id, 'qty' => 1]],
        ]))->assertSessionHas('success');

        $balance = StockBalance::query()->where('stock_item_id', $item->id)->firstOrFail();
        $this->assertEquals(16.0, (float) $balance->qty);
        $this->assertEquals(800.0, (float) $balance->value);
        $this->assertEquals(200.0, (float) $job->fresh()->cost);
        $this->assertEquals(9.0, (float) TripExpense::query()->where('maintenance_job_id', $job->id)->value('amount'));

        $this->patch("/office/maintenance/{$job->id}", $this->job([
            'source' => 'own', 'store_id' => $store->id, 'status' => 'open', 'cost' => null, 'completed_at' => null,
            'lines' => [['item_id' => $item->id, 'qty' => 4]],
        ]))->assertSessionHas('success');

        $this->assertEquals(20.0, (float) $balance->fresh()->qty);
        $this->assertEquals(1000.0, (float) $balance->fresh()->value);
        $this->assertNull($job->fresh()->cost);
        $this->assertFalse($job->fresh()->shares_trips);
        $this->assertSame(0, TripExpense::query()->where('maintenance_job_id', $job->id)->count());
        $this->assertSame('maintenance', $this->truck->fresh()->status);
    }

    public function test_a_second_store_can_be_added_and_a_bad_receipt_or_a_repeated_item_is_refused(): void
    {
        $this->setUpFleet();
        $this->turnWorkshopOn();
        $this->actingAs($this->admin, 'web');
        $store = Store::query()->where('is_default', true)->firstOrFail();

        $this->post('/office/stock/stores', ['name' => 'مخزن خارجي'])->assertSessionHas('success');
        $this->assertSame(2, Store::query()->count());
        $this->assertTrue(Store::query()->where('name', 'ورشة الشركة')->where('is_default', true)->exists());

        $this->post('/office/stock/items', ['name' => 'شحم', 'unit' => 'kg'])->assertSessionHas('success');
        Cache::store(config('cache.durable'))->flush();
        $this->post('/office/stock/items', ['name' => 'شحم', 'unit' => 'kg'])->assertSessionHas('error');
        $this->assertSame(1, StockItem::query()->count());

        $item = StockItem::query()->firstOrFail();
        $this->post('/office/stock/receipts', [
            'store_id' => $store->id, 'stock_item_id' => $item->id, 'qty' => 0, 'unit_price' => 10,
        ])->assertSessionHasErrors('qty');
        $this->assertSame(0, StockBalance::query()->count());

        $this->actingAs($this->officeUser($this->co, ['maintenance.view']), 'web')
            ->post('/office/stock/receipts', [
                'store_id' => $store->id, 'stock_item_id' => $item->id, 'qty' => 1, 'unit_price' => 10,
            ])->assertForbidden();

        $this->actingAs($this->companyAdmin(), 'web')->post('/office/stock/receipts', [
            'store_id' => $store->id, 'stock_item_id' => $item->id, 'qty' => 1, 'unit_price' => 10,
        ])->assertSessionHasErrors('store_id');
        $this->assertSame(0, StockBalance::query()->withoutGlobalScopes()->count());
    }

    public function test_a_company_without_a_workshop_cannot_issue_from_stock(): void
    {
        $this->setUpFleet();
        $store = app(StockService::class)->ensureDefault($this->co->id);
        $item = StockItem::query()->create(['company_id' => $this->co->id, 'name' => 'زيت', 'unit' => 'litre']);
        $this->actingAs($this->admin, 'web');

        $this->post('/office/maintenance', $this->job([
            'source' => 'own', 'store_id' => $store->id, 'lines' => [['item_id' => $item->id, 'qty' => 1]],
        ]))->assertSessionHas('error');
        $this->assertSame(0, MaintenanceJob::query()->count());
    }

    private function turnWorkshopOn(): void
    {
        CompanySetting::for($this->co->id)->update(['has_workshop' => true]);
        app(StockService::class)->ensureDefault($this->co->id);
    }

    /** @param array<string,mixed> $overrides */
    private function job(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $this->truck->id, 'kind' => 'periodic', 'category' => 'oil', 'status' => 'done', 'source' => 'external',
            'opened_at' => now()->subHours(3)->format('Y-m-d H:i'), 'completed_at' => now()->subHour()->format('Y-m-d H:i'),
            'odometer_km' => 52000, 'cost' => 800, 'note' => null, 'interval_km' => 10000, 'interval_days' => null,
        ], $overrides);
    }
}
