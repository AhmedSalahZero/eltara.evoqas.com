<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: office notifications (Step 7, Scope §11)
//  Location: tests/Feature/NotificationsStep7Test.php
//  Feature doc: docs/STEP_07_DASHBOARD_REPORTS_NOTIFICATIONS_AUDIT.md
//  Transfer needs approval · delivery · documents expiring ·
//  driver app not synced · the full notifications page.
// ══════════════════════════════════════════════════════════════════

class NotificationsStep7Test extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('trip_files');
        Cache::flush();
    }

    private function keys(User $user): array
    {
        return $user->notifications()->get()->pluck('data.key')->all();
    }

    public function test_a_transfer_that_needs_approval_notifies_the_approvers_only(): void
    {
        $trip = $this->runningTrip('approval');
        $approver = $this->officeUser($this->co, ['wallet_transfers.view', 'wallet_transfers.approve'], ['approval_limit' => 1000]);
        $viewer = $this->officeUser($this->co, ['wallet_transfers.view']);
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 2000])->assertSessionHas('success');

        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 500, 'reason' => 'Diesel'])->assertSessionHas('success');

        $this->assertContains('transfer.pending', $this->keys($this->admin));
        $this->assertContains('transfer.pending', $this->keys($approver));
        $this->assertNotContains('transfer.pending', $this->keys($viewer));
        $line = $approver->notifications()->first()->data;
        $this->assertSame($trip->number, $line['params']['trip']);
        $this->assertEquals(500, $line['params']['amount']);
    }

    public function test_an_approver_whose_limit_is_too_low_is_not_bothered(): void
    {
        $trip = $this->runningTrip('approval');
        $small = $this->officeUser($this->co, ['wallet_transfers.view', 'wallet_transfers.approve'], ['approval_limit' => 100]);
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);

        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 500, 'reason' => 'Diesel']);

        $this->assertNotContains('transfer.pending', $this->keys($small));
    }

    public function test_an_automatic_transfer_does_not_ask_for_approval(): void
    {
        $trip = $this->runningTrip('auto');
        $this->actingAs($this->admin, 'web');
        $this->post("/office/trips/{$trip->id}/collections", ['amount' => 2000]);

        $this->post("/office/trips/{$trip->id}/transfers", ['amount' => 500, 'reason' => 'Diesel'])->assertSessionHas('success');

        $this->assertNotContains('transfer.pending', $this->keys($this->admin));
    }

    public function test_a_delivery_tells_the_office_who_can_settle(): void
    {
        $trip = $this->runningTrip();
        $settler = $this->officeUser($this->co, ['trip_settlement.view']);
        $other = $this->officeUser($this->co, ['vehicles.view']);
        $this->actingAs($this->admin, 'web');

        $this->post("/office/trips/{$trip->id}/deliver", ['pod' => $this->pod(), 'receiver' => 'Store keeper'])->assertSessionHas('success');

        $this->assertContains('trip.delivered_office', $this->keys($settler));
        $this->assertNotContains('trip.delivered_office', $this->keys($other));
        $this->assertSame(route('office.trips.show', $trip, false), $settler->notifications()->first()->data['url']);
    }

    public function test_the_daily_job_announces_documents_on_the_set_days_once(): void
    {
        $this->setUpFleet();
        $this->truck->forceFill(['licence_expires_at' => today()->addDays(7), 'insurance_expires_at' => today()->addDays(20)])->save();
        $this->drv->forceFill(['license_expires_at' => today()->addDays(30)])->save();

        Artisan::call('documents:notify-expiring');
        Artisan::call('documents:notify-expiring');         // a second run on the same day adds nothing

        $lines = $this->admin->notifications()->get();
        $this->assertCount(2, $lines);                      // the licence at 7 days and the driving licence at 30; the insurance at 20 days is not a step day
        $this->assertEqualsCanonicalizing(['licence', 'driving'], $lines->pluck('data.params.document')->all());
        $this->assertSame(7, $lines->firstWhere('data.params.document', 'licence')->data['params']['days']);
    }

    public function test_the_hourly_job_flags_a_driver_on_the_road_who_has_not_synced(): void
    {
        $this->runningTrip('limit', 1000, ['max_hours_without_sync' => 24]);
        $this->drv->forceFill(['last_sync_at' => now()->subHours(30)])->save();

        Artisan::call('drivers:notify-unsynced');
        Artisan::call('drivers:notify-unsynced');           // once per silence

        $this->assertSame(1, $this->admin->notifications()->count());
        $line = $this->admin->notifications()->first()->data;
        $this->assertSame('driver.unsynced', $line['key']);
        $this->assertSame($this->drv->name, $line['params']['driver']);
        $this->assertSame(24, $line['params']['hours']);
    }

    public function test_a_driver_who_synced_recently_or_is_not_on_the_road_is_left_alone(): void
    {
        $this->runningTrip('limit', 1000, ['max_hours_without_sync' => 24]);
        $this->drv->forceFill(['last_sync_at' => now()->subHours(2)])->save();
        Artisan::call('drivers:notify-unsynced');
        $this->assertSame(0, $this->admin->notifications()->count());

        $this->drv->forceFill(['last_sync_at' => now()->subHours(50)])->save();
        Trip::query()->withoutGlobalScopes()->update(['status' => 'settled']);
        Artisan::call('drivers:notify-unsynced');
        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_the_notifications_page_lists_everything_and_can_show_unread_only(): void
    {
        $this->setUpFleet();
        $this->admin->notify(new \App\Notifications\AppNotification('document.expiring', ['owner' => 'X', 'document' => 'licence', 'days' => 7, 'date' => '2026-10-08']));
        $this->admin->notify(new \App\Notifications\AppNotification('trip.delivered_office', ['number' => 'T-00001', 'driver' => 'Y']));
        $this->admin->notifications()->first()->markAsRead();

        $this->actingAs($this->admin, 'web')->get('/office/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')->has('items.data', 2)->where('unread', 1)->where('filter', 'all'));
        $this->get('/office/notifications?filter=unread')->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('filter', 'unread'));
    }

    public function test_the_client_has_the_page_too_and_sees_only_his_own(): void
    {
        $this->setUpFleet();
        $client = $this->clientUser($this->co);
        $client->notify(new \App\Notifications\AppNotification('trip.delivered', ['number' => 'T-00009']));
        $this->admin->notify(new \App\Notifications\AppNotification('trip.delivered_office', ['number' => 'T-00001', 'driver' => 'Y']));

        $this->actingAs($client, 'client')->get('/client/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')->has('items.data', 1)->where('items.data.0.key', 'trip.delivered'));
    }
}
