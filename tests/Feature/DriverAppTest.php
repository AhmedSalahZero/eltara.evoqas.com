<?php

namespace Tests\Feature;

use App\Models\DriverUpload;
use App\Models\TripEvent;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Services\Trips\TripService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the Driver App's trip work (Step 4)
//  Location: tests/Feature/DriverAppTest.php
//  Feature doc: docs/STEP_04_DRIVER_APP.md
//
//  The phone sends entries (accept, loading, depart, expense, cash,
//  transfer, delivery) and photos. Here we play the phone: upload a
//  photo, then sync entries that refer to it — and check the office
//  numbers come out right, at the time the driver did it.
// ══════════════════════════════════════════════════════════════════

class DriverAppTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('trip_files');
    }

    private function upload(string $kind = 'receipt', ?string $uuid = null): string
    {
        $uuid ??= (string) Str::uuid();
        $this->postJson('/driver/api/uploads', ['uuid' => $uuid, 'kind' => $kind, 'file' => UploadedFile::fake()->image('p.jpg', 400, 300)])
            ->assertOk()->assertJsonPath('ok', true);

        return $uuid;
    }

    /** One entry, synced; returns the result row. */
    private function sync(string $type, array $payload, ?string $at = null): array
    {
        $response = $this->postJson('/driver/api/sync', ['items' => [[
            'uuid' => (string) Str::uuid(), 'type' => $type, 'payload' => $payload, 'recorded_at' => $at ?? now()->subHour()->utc()->toIso8601ZuluString(),
        ]]])->assertOk();

        return $response->json('results.0');
    }

    /** A trip the office created and nobody has accepted yet. */
    private function plannedTrip(array $settings = []): Trip
    {
        $this->setUpFleet($settings);

        return Tenant::forCompany($this->co->id, fn () => app(TripService::class)->create($this->tripData(['transfer_policy' => 'limit', 'auto_transfer_limit' => 1000]), $this->admin));
    }

    public function test_a_photo_is_stored_privately_and_sending_it_twice_keeps_one_copy(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->drv, 'driver');

        $uuid = $this->upload('receipt');
        $this->upload('receipt', $uuid);

        $this->assertSame(1, DriverUpload::query()->withoutGlobalScopes()->count());
        Storage::disk('trip_files')->assertExists(DriverUpload::query()->withoutGlobalScopes()->first()->path);
    }

    public function test_a_photo_id_of_another_driver_is_refused_and_files_must_be_images(): void
    {
        $this->setUpFleet();
        $uuid = $this->actingAs($this->drv, 'driver')->upload();

        $other = $this->driver($this->co, ['mobile' => '01208888888']);
        $this->actingAs($other, 'driver')->postJson('/driver/api/uploads', ['uuid' => $uuid, 'kind' => 'receipt', 'file' => UploadedFile::fake()->image('p.jpg')])->assertForbidden();
        $this->postJson('/driver/api/uploads', ['uuid' => (string) Str::uuid(), 'kind' => 'receipt', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertStatus(422);
    }

    public function test_the_driver_takes_a_trip_from_accept_to_delivery_offline_and_the_times_are_his(): void
    {
        $trip = $this->plannedTrip();
        $this->actingAs($this->drv, 'driver');
        $t0 = now()->subHours(6)->startOfMinute();

        $this->assertSame('applied', $this->sync('trip.accept', ['trip_id' => $trip->id], $t0->copy()->utc()->toIso8601ZuluString())['status']);
        // Custody is handed over by the office (Step 3), then the driver carries on.
        Tenant::forCompany($this->co->id, fn () => app(TripService::class)->issueCustody($trip->fresh(), 3000, Actor::user($this->admin)));

        $this->assertSame('applied', $this->sync('trip.loading', ['trip_id' => $trip->id], $t0->copy()->addHour()->utc()->toIso8601ZuluString())['status']);
        $this->assertSame('applied', $this->sync('trip.depart', ['trip_id' => $trip->id], $t0->copy()->addHours(2)->utc()->toIso8601ZuluString())['status']);

        // Delivering without the stamped note is refused with the rule's own message.
        $refused = $this->sync('trip.delivery', ['trip_id' => $trip->id, 'receiver' => 'Ahmed']);
        $this->assertSame('rejected', $refused['status']);
        $this->assertSame(__('trips.no_pod'), $refused['message']);

        $pod = $this->upload('pod');
        $this->assertSame('applied', $this->sync('trip.delivery', ['trip_id' => $trip->id, 'photo' => $pod, 'receiver' => 'Ahmed'], $t0->copy()->addHours(5)->utc()->toIso8601ZuluString())['status']);

        $trip = Trip::query()->withoutGlobalScopes()->find($trip->id);
        $this->assertSame('delivered', $trip->status);
        $this->assertSame('Ahmed', $trip->pod_receiver);
        $this->assertNotNull($trip->pod_path);
        $this->assertSame($t0->toDateTimeString(), $trip->accepted_at->toDateTimeString());
        $this->assertSame($t0->copy()->addHours(5)->toDateTimeString(), $trip->delivered_at->toDateTimeString());
    }

    public function test_an_expense_with_its_photo_comes_out_of_custody_at_the_time_it_was_spent(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->drv, 'driver');
        $spent = now()->subHours(3)->startOfMinute();

        $result = $this->sync('trip.expense', [
            'trip_id' => $trip->id, 'expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 2000.5,
            'note' => 'Diesel', 'photo' => $this->upload(),
        ], $spent->copy()->utc()->toIso8601ZuluString());

        $this->assertSame('applied', $result['status']);
        $this->assertEquals(999.5, $this->balances($trip)['custody']);

        $expense = $trip->expenses()->withoutGlobalScopes()->first();
        $this->assertSame('driver', $expense->source);
        $this->assertNotNull($expense->receipt_path);
        $this->assertSame($spent->toDateTimeString(), $expense->spent_at->toDateTimeString());
    }

    public function test_a_receipt_photo_is_required_when_the_company_says_so(): void
    {
        $trip = $this->runningTrip('limit', 1000, ['receipt_photo_required' => true]);
        $this->actingAs($this->drv, 'driver');
        $entry = ['trip_id' => $trip->id, 'expense_category_id' => $this->cats['toll'], 'paid_from' => 'custody', 'amount' => 100];

        $refused = $this->sync('trip.expense', $entry);
        $this->assertSame('rejected', $refused['status']);
        $this->assertSame(__('trips.receipt_required'), $refused['message']);
        $this->assertEquals(3000, $this->balances($trip)['custody']);

        \App\Models\CompanySetting::for($this->co->id)->update(['receipt_photo_required' => false]);
        $this->assertSame('applied', $this->sync('trip.expense', $entry)['status']);
    }

    public function test_an_expense_with_a_photo_id_that_never_arrived_is_refused(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->drv, 'driver');

        $result = $this->sync('trip.expense', ['trip_id' => $trip->id, 'expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 50, 'photo' => (string) Str::uuid()]);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame(__('trips.driver_photo_missing'), $result['message']);
    }

    public function test_cash_from_the_client_then_a_transfer_inside_the_limit_moves_at_once(): void
    {
        $trip = $this->runningTrip('limit', 1000);
        $this->actingAs($this->drv, 'driver');

        $cash = $this->sync('trip.collection', ['trip_id' => $trip->id, 'amount' => 5000, 'note' => 'Cash from site', 'photo' => $this->upload('collection')]);
        $this->assertSame('applied', $cash['status']);
        $this->assertSame('awaiting_client', $cash['result']['state']);
        $this->assertEquals(5000, $this->balances($trip)['collections']);

        $small = $this->sync('trip.transfer', ['trip_id' => $trip->id, 'amount' => 800, 'reason' => 'Fuel top-up']);
        $this->assertSame('auto', $small['result']['status']);
        $this->assertEquals(3800, $this->balances($trip)['custody']);

        $big = $this->sync('trip.transfer', ['trip_id' => $trip->id, 'amount' => 2000, 'reason' => 'Repair']);
        $this->assertSame('pending', $big['result']['status']);
        $this->assertEquals(3800, $this->balances($trip)['custody'], 'a pending transfer moves nothing');

        $tooMuch = $this->sync('trip.transfer', ['trip_id' => $trip->id, 'amount' => 99999, 'reason' => 'x']);
        $this->assertSame('rejected', $tooMuch['status']);
    }

    public function test_cash_the_client_recorded_counts_only_after_the_driver_confirms_it(): void
    {
        $trip = $this->runningTrip();
        $collection = Tenant::forCompany($this->co->id, fn () => app(CollectionService::class)->record($trip, 1500, 'client', Actor::system()));
        $this->assertEquals(0, $this->balances($trip)['collections']);

        $this->actingAs($this->drv, 'driver');
        $this->assertSame('applied', $this->sync('collection.confirm', ['collection_id' => $collection->id])['status']);

        $this->assertEquals(1500, $this->balances($trip)['collections']);
        $this->assertSame('confirmed', TripCollection::query()->withoutGlobalScopes()->find($collection->id)->state());
    }

    public function test_the_driver_can_dispute_cash_he_did_not_receive_and_it_stays_out_of_his_wallet(): void
    {
        $trip = $this->runningTrip();
        $collection = Tenant::forCompany($this->co->id, fn () => app(CollectionService::class)->record($trip, 900, 'client', Actor::system()));

        $this->actingAs($this->drv, 'driver');
        $result = $this->sync('collection.dispute', ['collection_id' => $collection->id, 'note' => 'Nothing was paid']);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('disputed', $result['result']['state']);
        $this->assertEquals(0, $this->balances($trip)['collections']);
    }

    public function test_a_driver_cannot_touch_another_drivers_trip_or_cash(): void
    {
        $trip = $this->runningTrip();
        $other = $this->driver($this->co, ['mobile' => '01207777777']);
        $collection = Tenant::forCompany($this->co->id, fn () => app(CollectionService::class)->record($trip, 100, 'client', Actor::system()));

        $this->actingAs($other, 'driver');
        $this->assertSame('rejected', $this->sync('trip.expense', ['trip_id' => $trip->id, 'expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 10])['status']);
        $this->assertSame('rejected', $this->sync('collection.confirm', ['collection_id' => $collection->id])['status']);
        $this->assertEquals(3000, $this->balances($trip)['custody']);
    }

    public function test_the_same_expense_sent_twice_is_recorded_once(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->drv, 'driver');
        $item = ['uuid' => (string) Str::uuid(), 'type' => 'trip.expense', 'recorded_at' => now()->subMinute()->toIso8601String(),
            'payload' => ['trip_id' => $trip->id, 'expense_category_id' => $this->cats['fuel'], 'paid_from' => 'custody', 'amount' => 400, 'photo' => $this->upload()]];

        $this->postJson('/driver/api/sync', ['items' => [$item]])->assertJsonPath('results.0.status', 'applied');
        $this->postJson('/driver/api/sync', ['items' => [$item]])->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, $trip->expenses()->withoutGlobalScopes()->count());
        $this->assertEquals(2600, $this->balances($trip)['custody']);
    }

    public function test_the_snapshot_holds_only_my_open_trips_and_never_a_price(): void
    {
        $trip = $this->runningTrip();
        $otherDriver = $this->driver($this->co, ['mobile' => '01206666666']);
        \App\Models\Vehicle::factory()->for($this->co)->create(['driver_id' => $otherDriver->id]);

        $response = $this->actingAs($this->drv, 'driver')->getJson('/driver/api/snapshot')->assertOk();

        $this->assertCount(1, $response->json('trips'));
        $this->assertSame($trip->number, $response->json('trips.0.number'));
        $this->assertEquals(3000, $response->json('trips.0.wallets.custody'));
        $this->assertNotEmpty($response->json('categories'));

        $json = $response->getContent();
        foreach (['freight', 'price', 'revenue', 'profit', 'margin'] as $word) {
            $this->assertStringNotContainsString($word, $json, "the snapshot must not mention '{$word}'");
        }

        $this->actingAs($otherDriver, 'driver')->getJson('/driver/api/snapshot')->assertOk()->assertJsonCount(0, 'trips');
    }

    public function test_the_driver_signs_for_the_custody_once_and_the_signature_is_kept_with_the_trip(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->drv, 'driver');

        $signature = $this->upload('signature');
        $result = $this->sync('trip.custody_receive', ['trip_id' => $trip->id, 'signature' => $signature]);

        $this->assertSame('applied', $result['status']);
        $this->assertEquals(3000, $result['result']['amount']);

        $event = TripEvent::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->where('type', 'custody_received')->firstOrFail();
        $this->assertEquals(3000, $event->meta['amount']);
        $this->assertStringStartsWith("trips/{$trip->id}/custody/", $event->meta['signature']);
        Storage::disk('trip_files')->assertExists($event->meta['signature']);

        $again = $this->sync('trip.custody_receive', ['trip_id' => $trip->id, 'signature' => $this->upload('signature')]);
        $this->assertSame('rejected', $again['status']);
        $this->assertSame(__('trips.driver_custody_already'), $again['message']);
    }

    public function test_a_custody_top_up_must_be_signed_for_too_and_only_for_the_new_amount(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->drv, 'driver');

        $this->assertSame('applied', $this->sync('trip.custody_receive', ['trip_id' => $trip->id, 'signature' => $this->upload('signature')])['status']);
        $this->assertTrue($this->actingAs($this->drv, 'driver')->getJson('/driver/api/snapshot')->json('trips.0.custody.received'));

        // Nothing new handed over: a second signature is refused.
        $this->assertSame('rejected', $this->sync('trip.custody_receive', ['trip_id' => $trip->id, 'signature' => $this->upload('signature')])['status']);

        // The office tops up: the trip needs a new signature, for the top-up only.
        Tenant::forCompany($this->co->id, fn () => app(TripService::class)->issueCustody($trip->fresh(), 1000, Actor::user($this->admin)));
        $custody = $this->getJson('/driver/api/snapshot')->json('trips.0.custody');
        $this->assertFalse($custody['received']);
        $this->assertEquals(4000, $custody['issued']);
        $this->assertEquals(3000, $custody['signed']);

        $result = $this->sync('trip.custody_receive', ['trip_id' => $trip->id, 'signature' => $this->upload('signature')]);
        $this->assertSame('applied', $result['status']);
        $this->assertEquals(1000, $result['result']['amount']);

        $events = TripEvent::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->where('type', 'custody_received')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertEquals(1000, $events[1]->meta['amount']);
        $this->assertEquals(4000, $events[1]->meta['total']);
        $this->assertTrue($events[1]->meta['top_up']);
        $this->assertTrue($this->getJson('/driver/api/snapshot')->json('trips.0.custody.received'));
    }

    public function test_an_old_photo_from_the_gallery_is_refused_but_a_fresh_one_and_a_signature_are_accepted(): void
    {
        $this->setUpFleet();
        $this->actingAs($this->drv, 'driver');
        $saved = now()->utc();
        $file = fn () => UploadedFile::fake()->image('p.jpg', 400, 300);

        // Made two days before the driver saved it: an old receipt.
        $this->postJson('/driver/api/uploads', ['uuid' => (string) Str::uuid(), 'kind' => 'receipt', 'file' => $file(),
            'taken_at' => $saved->copy()->subDays(2)->toIso8601ZuluString(), 'captured_at' => $saved->toIso8601ZuluString()])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(0, DriverUpload::query()->withoutGlobalScopes()->count());

        // Made a minute earlier: fine. (Offline, the upload itself may come hours later — that does not matter.)
        $this->postJson('/driver/api/uploads', ['uuid' => (string) Str::uuid(), 'kind' => 'receipt', 'file' => $file(),
            'taken_at' => $saved->copy()->subMinute()->toIso8601ZuluString(), 'captured_at' => $saved->toIso8601ZuluString()])->assertOk();

        // A finger signature has no "taken" time and is never checked.
        $this->postJson('/driver/api/uploads', ['uuid' => (string) Str::uuid(), 'kind' => 'signature', 'file' => $file(),
            'taken_at' => $saved->copy()->subDays(2)->toIso8601ZuluString(), 'captured_at' => $saved->toIso8601ZuluString()])->assertOk();
    }

    public function test_the_driver_cannot_sign_for_custody_the_office_has_not_issued(): void
    {
        $trip = $this->plannedTrip();
        $this->actingAs($this->drv, 'driver');
        $this->sync('trip.accept', ['trip_id' => $trip->id]);

        $result = $this->sync('trip.custody_receive', ['trip_id' => $trip->id, 'signature' => $this->upload('signature')]);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame(__('trips.driver_custody_not_issued'), $result['message']);
    }

    public function test_a_custody_top_up_request_reaches_the_trip_timeline(): void
    {
        $trip = $this->runningTrip();
        $this->actingAs($this->drv, 'driver');

        $this->assertSame('applied', $this->sync('trip.custody_request', ['trip_id' => $trip->id, 'amount' => 1500, 'note' => 'Diesel price higher'])['status']);

        $event = TripEvent::query()->withoutGlobalScopes()->where('trip_id', $trip->id)->where('type', 'custody_requested')->firstOrFail();
        $this->assertEquals(1500, $event->meta['amount']);
        $this->assertSame('Diesel price higher', $event->note);
        $this->assertEquals(3000, $this->balances($trip)['custody'], 'a request moves no money');
    }

    public function test_cash_needs_the_signed_receipt_photo_when_the_company_requires_photos(): void
    {
        $trip = $this->runningTrip('limit', 1000, ['receipt_photo_required' => true]);
        $this->actingAs($this->drv, 'driver');

        $refused = $this->sync('trip.collection', ['trip_id' => $trip->id, 'amount' => 500]);
        $this->assertSame('rejected', $refused['status']);
        $this->assertSame(__('trips.receipt_required'), $refused['message']);
        $this->assertEquals(0, $this->balances($trip)['collections']);
    }

    public function test_the_snapshot_carries_custody_budget_truck_documents_advances_and_history(): void
    {
        $trip = $this->runningTrip();

        $json = $this->actingAs($this->drv, 'driver')->getJson('/driver/api/snapshot')->assertOk()->json();

        $this->assertEquals(3000, $json['trips'][0]['custody']['issued']);
        $this->assertFalse($json['trips'][0]['custody']['received']);
        $this->assertNotEmpty($json['trips'][0]['custody']['budget'], 'what the custody is meant for');
        $this->assertSame(trim($this->truck->plate_number.' '.$this->truck->plate_letters), $json['profile']['vehicle']['plate']);
        $this->assertArrayHasKey('insurance', $json['profile']['vehicle']['documents']);
        $this->assertSame([], $json['advances']);
        $this->assertSame([], $json['history']);
        $this->assertEquals(15, $json['settings']['over_budget_percent']);
    }

    public function test_a_signed_out_phone_is_told_so_and_a_read_only_company_cannot_upload(): void
    {
        $this->getJson('/driver/api/snapshot')->assertUnauthorized();

        $this->setUpFleet();
        $this->co->forceFill(['subscription_ends_at' => now()->subDays(40)])->save();
        $this->actingAs($this->drv, 'driver')
            ->postJson('/driver/api/uploads', ['uuid' => (string) Str::uuid(), 'kind' => 'receipt', 'file' => UploadedFile::fake()->image('p.jpg')])
            ->assertStatus(423);
    }
}
