<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Services\Trips\Actor;
use App\Services\Trips\TripService;

// ══════════════════════════════════════════════════════════════════
//  El Tara — StartLoadingHandler (Driver App entry "trip.loading")
//  Location: app/Sync/Handlers/StartLoadingHandler.php
//
//  The driver started loading.
// ══════════════════════════════════════════════════════════════════

final class StartLoadingHandler extends TripEntryHandler
{
    public function __construct(private readonly TripService $trips) {}

    public function rules(): array
    {
        return ['trip_id' => ['required', 'integer'], ...self::LOCATION_RULES];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $trip = $this->trip($driver, (int) $payload['trip_id']);
        [$lat, $lng] = $this->location($driver, $payload);

        $this->trips->startLoading($trip, $actor, $lat, $lng, $at);

        return ['status' => $trip->refresh()->status];
    }
}
