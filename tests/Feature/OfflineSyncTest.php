<?php

namespace Tests\Feature;

use App\Models\SyncReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: offline entries uploading from the Driver App
//  Location: tests/Feature/OfflineSyncTest.php
//  Feature doc: docs/STEP_01_FOUNDATION.md §6 (offline sync)
// ══════════════════════════════════════════════════════════════════

class OfflineSyncTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function entry(array $payload = ['language' => 'en'], string $type = 'driver.preferences', ?string $uuid = null): array
    {
        return ['uuid' => $uuid ?? (string) Str::uuid(), 'type' => $type, 'payload' => $payload, 'recorded_at' => now()->subHour()->toIso8601String()];
    }

    public function test_an_offline_entry_is_applied_once_and_a_resend_is_a_harmless_duplicate(): void
    {
        $driver = $this->driver();
        $item = $this->entry(['language' => 'en', 'theme' => 'light']);

        $this->actingAs($driver, 'driver')->postJson('/driver/api/sync', ['items' => [$item]])
            ->assertOk()->assertJsonPath('results.0.status', 'applied');

        $this->assertSame('en', $driver->fresh()->language);
        $this->assertSame('light', $driver->fresh()->theme);

        // The phone lost the answer and sends the same entry again.
        $this->postJson('/driver/api/sync', ['items' => [$item]])->assertJsonPath('results.0.status', 'duplicate');
        $this->assertSame(1, SyncReceipt::query()->count());
    }

    public function test_entries_are_processed_in_order_and_one_bad_entry_does_not_block_the_rest(): void
    {
        $driver = $this->driver();

        $response = $this->actingAs($driver, 'driver')->postJson('/driver/api/sync', ['items' => [
            $this->entry(['language' => 'en']),
            $this->entry([], 'no.such.type'),
            $this->entry(['language' => 'fr']),
            $this->entry(['language' => 'ar']),
        ]])->assertOk();

        $this->assertSame(['applied', 'rejected', 'rejected', 'applied'], array_column($response->json('results'), 'status'));
        $this->assertSame('ar', $driver->fresh()->language);
        $this->assertNotNull($driver->fresh()->last_sync_at);
    }

    public function test_an_unexpected_fault_does_not_break_the_batch_and_nothing_is_lost(): void
    {
        $driver = $this->driver();
        config(['sync.handlers' => ['test.boom' => ExplodingHandler::class, 'driver.preferences' => \App\Sync\Handlers\DriverPreferencesHandler::class]]);

        $first = $this->entry(['language' => 'en']);
        $boom = $this->entry([], 'test.boom');
        $after = $this->entry(['language' => 'ar']);

        // The fault is not a business rule: the upload still answers 200, the earlier entry is saved,
        // the faulty one is 'failed' and the one after it is 'retry' (order kept) — never a server error.
        $response = $this->actingAs($driver, 'driver')->postJson('/driver/api/sync', ['items' => [$first, $boom, $after]])->assertOk();

        $this->assertSame(['applied', 'failed', 'retry'], array_column($response->json('results'), 'status'));
        $this->assertSame('en', $driver->fresh()->language);

        // No receipt for the failed or the untried entry, so the phone can send them again.
        $this->assertSame(1, SyncReceipt::query()->count());
        $this->assertSame(0, SyncReceipt::query()->whereIn('uuid', [$boom['uuid'], $after['uuid']])->count());

        // Sent again once the fault is gone: the first is a duplicate, the other applies.
        $again = $this->postJson('/driver/api/sync', ['items' => [$first, $after]])->assertOk();
        $this->assertSame(['duplicate', 'applied'], array_column($again->json('results'), 'status'));
        $this->assertSame('ar', $driver->fresh()->language);
    }

    public function test_one_driver_cannot_use_another_drivers_entry_id(): void
    {
        $company = $this->company();
        $first = $this->driver($company);
        $second = $this->driver($company, ['mobile' => '01209999999']);
        $item = $this->entry();

        $this->actingAs($first, 'driver')->postJson('/driver/api/sync', ['items' => [$item]])->assertJsonPath('results.0.status', 'applied');

        $this->actingAs($second, 'driver')->postJson('/driver/api/sync', ['items' => [$item]])->assertJsonPath('results.0.status', 'rejected');
    }

    public function test_the_batch_must_be_well_formed_and_not_too_large(): void
    {
        $driver = $this->driver();

        $this->actingAs($driver, 'driver')->postJson('/driver/api/sync', ['items' => [['uuid' => 'not-a-uuid', 'type' => 'x']]])->assertStatus(422);

        $many = array_map(fn () => $this->entry(), range(1, 51));
        $this->postJson('/driver/api/sync', ['items' => $many])->assertStatus(422);
    }

    public function test_when_the_subscription_has_ended_nothing_is_recorded_and_the_phone_keeps_the_entries(): void
    {
        $driver = $this->driver($this->company(['subscription_ends_at' => today()->subDay()]));

        $this->actingAs($driver, 'driver')->postJson('/driver/api/sync', ['items' => [$this->entry()]])->assertStatus(423);
        $this->assertSame(0, SyncReceipt::query()->count());
        $this->assertSame('ar', $driver->fresh()->language);
    }

    public function test_old_receipts_are_tidied_by_the_daily_task(): void
    {
        $driver = $this->driver();
        $this->actingAs($driver, 'driver')->postJson('/driver/api/sync', ['items' => [$this->entry()]]);
        SyncReceipt::query()->update(['processed_at' => now()->subDays(91)]);

        $this->artisan('sync:prune-receipts')->assertSuccessful();
        $this->assertSame(0, SyncReceipt::query()->count());
    }
}

/** A handler that fails the way a bug or a database hiccup would (not a business rule). */
class ExplodingHandler implements \App\Sync\SyncHandler
{
    public function rules(): array
    {
        return [];
    }

    public function handle(\App\Models\Driver $driver, array $payload, ?\DateTimeInterface $recordedAt): array
    {
        throw new \RuntimeException('boom');
    }
}
