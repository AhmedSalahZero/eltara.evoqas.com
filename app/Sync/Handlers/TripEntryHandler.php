<?php

namespace App\Sync\Handlers;

use App\Models\CompanySetting;
use App\Models\Driver;
use App\Models\DriverUpload;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Services\Trips\Actor;
use App\Services\Trips\TripRuleException;
use App\Sync\SyncHandler;
use App\Sync\SyncRejected;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripEntryHandler (shared by every trip entry of the app)
//  Location: app/Sync/Handlers/TripEntryHandler.php
//
//  An entry the phone recorded offline is replayed here through the
//  very same services the office uses (App\Services\Trips\*), so the
//  money rules are the same in both places. A rule that says no
//  (TripRuleException) becomes a refused entry with the rule's own
//  message, shown to the driver on the phone.
//
//  Subclasses implement run(); this class finds the driver's trip,
//  turns rule errors into refusals and resolves photo ids.
// ══════════════════════════════════════════════════════════════════

abstract class TripEntryHandler implements SyncHandler
{
    /** Where the phone was, when it could tell (only if the company allows it). */
    protected const LOCATION_RULES = [
        'lat' => ['nullable', 'numeric', 'between:-90,90'],
        'lng' => ['nullable', 'numeric', 'between:-180,180'],
    ];

    abstract protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array;

    final public function handle(Driver $driver, array $payload, ?\DateTimeInterface $recordedAt): array
    {
        try {
            return $this->run($driver, Actor::driver($driver), $payload, $this->clamp($recordedAt));
        } catch (TripRuleException $e) {
            throw new SyncRejected($e->getMessage());
        }
    }

    /** The driver's own trip, or a refusal. Never another driver's. */
    protected function trip(Driver $driver, int $id): Trip
    {
        $trip = Trip::query()->whereKey($id)->where('driver_id', $driver->id)->first();

        if (! $trip) {
            throw new SyncRejected(__('trips.driver_trip_missing'));
        }

        return $trip;
    }

    protected function collection(Driver $driver, int $id): TripCollection
    {
        $collection = TripCollection::query()->whereKey($id)->where('driver_id', $driver->id)->first();

        if (! $collection) {
            throw new SyncRejected(__('trips.driver_cash_missing'));
        }

        return $collection;
    }

    /** The stored path of a photo the phone uploaded earlier (by its uuid). */
    protected function photo(Driver $driver, ?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $upload = DriverUpload::query()->where('uuid', $uuid)->where('driver_id', $driver->id)->first();

        if (! $upload) {
            throw new SyncRejected(__('trips.driver_photo_missing'));
        }

        return $upload->path;
    }

    protected function photoRequired(Driver $driver): bool
    {
        return (bool) CompanySetting::for($driver->company_id)->receipt_photo_required;
    }

    /** Only keep the location when the company asked for it. */
    protected function location(Driver $driver, array $payload): array
    {
        if (! CompanySetting::for($driver->company_id)->capture_location) {
            return [null, null];
        }

        return [isset($payload['lat']) ? (float) $payload['lat'] : null, isset($payload['lng']) ? (float) $payload['lng'] : null];
    }

    /**
     * The phone sends its time in UTC ("…Z"); the database keeps the
     * company's local time, so convert first — otherwise every offline
     * entry would be off by the time-zone difference. A phone clock
     * running ahead must not write times in the future either.
     */
    private function clamp(?\DateTimeInterface $at): ?\DateTimeInterface
    {
        if (! $at) {
            return null;
        }

        $local = Carbon::instance($at)->setTimezone(config('app.timezone'));

        return $local->greaterThan(now()) ? now() : $local;
    }
}
