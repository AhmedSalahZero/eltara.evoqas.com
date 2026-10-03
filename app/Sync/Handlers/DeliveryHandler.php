<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Services\Trips\Actor;
use App\Services\Trips\TripService;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DeliveryHandler (Driver App entry "trip.delivery")
//  Location: app/Sync/Handlers/DeliveryHandler.php
//
//  Delivery: the photo of the stamped delivery note (uploaded earlier
//  under its uuid) and the receiver's name. TripService refuses it
//  without the photo (Scope §6.5).
// ══════════════════════════════════════════════════════════════════

final class DeliveryHandler extends TripEntryHandler
{
    public function __construct(private readonly TripService $trips) {}

    public function rules(): array
    {
        return [
            'trip_id'  => ['required', 'integer'],
            'photo'    => ['nullable', 'uuid'],
            'receiver' => ['nullable', 'string', 'max:120'],
            ...self::LOCATION_RULES,
        ];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $trip = $this->trip($driver, (int) $payload['trip_id']);
        [$lat, $lng] = $this->location($driver, $payload);

        $this->trips->deliver($trip, $this->photo($driver, $payload['photo'] ?? null), $payload['receiver'] ?? null, $actor, $lat, $lng, $at);

        return ['status' => $trip->refresh()->status];
    }
}
