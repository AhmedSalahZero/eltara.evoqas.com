<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Models\Trip;
use App\Services\Trips\Actor;
use App\Services\Trips\TripRuleException;
use App\Services\Trips\TripService;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CustodyRequestHandler (Driver App entry "trip.custody_request")
//  Location: app/Sync/Handlers/CustodyRequestHandler.php
//
//  Scope §8.2 Home "custody ran out": the driver asks the office for
//  more custody. It is a request only — it appears in the trip's
//  timeline with the amount and note; the office answers by issuing a
//  top-up (Step 3), which reaches the phone at its next refresh.
// ══════════════════════════════════════════════════════════════════

final class CustodyRequestHandler extends TripEntryHandler
{
    public function __construct(private readonly TripService $trips) {}

    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'integer'],
            'amount'  => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'note'    => ['nullable', 'string', 'max:250'],
            ...self::LOCATION_RULES,
        ];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $trip = $this->trip($driver, (int) $payload['trip_id']);

        if (! in_array($trip->status, Trip::IN_PROGRESS, true) || $trip->is_hired) {
            throw TripRuleException::because('trips.custody_when');
        }

        [$lat, $lng] = $this->location($driver, $payload);
        $this->trips->event($trip, 'custody_requested', $actor, $payload['note'] ?? null, ['amount' => round((float) $payload['amount'], 2)], $lat, $lng, $at);

        return ['requested' => round((float) $payload['amount'], 2)];
    }
}
