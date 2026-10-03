<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Models\TripEvent;
use App\Services\Trips\Actor;
use App\Services\Trips\TripFigures;
use App\Services\Trips\TripRuleException;
use App\Services\Trips\TripService;
use App\Sync\SyncRejected;
use Illuminate\Support\Facades\Storage;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CustodyReceiveHandler (Driver App entry "trip.custody_receive")
//  Location: app/Sync/Handlers/CustodyReceiveHandler.php
//
//  Scope §8.2 "Receive custody": the driver signs with his finger that
//  he received the custody the office handed over. The signature image
//  (uploaded earlier under its uuid) is kept with the trip, and the
//  moment is recorded in the trip's timeline with the amount.
//  The money itself was already posted when the office issued it
//  (Step 3); this is the driver's acknowledgement.
//  Every handover needs a signature: after a top-up the driver signs
//  again, and that signature covers only the amount not yet signed.
// ══════════════════════════════════════════════════════════════════

final class CustodyReceiveHandler extends TripEntryHandler
{
    public function __construct(private readonly TripService $trips, private readonly TripFigures $figures) {}

    public function rules(): array
    {
        return ['trip_id' => ['required', 'integer'], 'signature' => ['required', 'uuid'], ...self::LOCATION_RULES];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $trip = $this->trip($driver, (int) $payload['trip_id']);

        if ($trip->custody_issued_at === null) {
            throw TripRuleException::because('trips.driver_custody_not_issued');
        }

        // Every amount the office hands over must be signed for. The driver signs again after a top-up:
        // what is already signed is the sum of the earlier signatures; the new signature covers the rest.
        $issued = (float) $this->figures->wallets($trip)['custody']['issued'];
        $signed = (float) TripEvent::query()->where('trip_id', $trip->id)->where('type', 'custody_received')->get()
            ->sum(fn (TripEvent $e) => (float) ($e->meta['amount'] ?? 0));
        $toSign = round($issued - $signed, 2);

        if ($toSign <= 0.004) {
            throw TripRuleException::because('trips.driver_custody_already');
        }

        $uploaded = $this->photo($driver, $payload['signature']);
        if (! $uploaded) {
            throw new SyncRejected(__('trips.driver_photo_missing'));
        }

        // Keep the signature with the trip (Scope §12 "Files … stored per trip").
        $path = "trips/{$trip->id}/custody/{$payload['signature']}.".pathinfo($uploaded, PATHINFO_EXTENSION);
        Storage::disk('trip_files')->copy($uploaded, $path);

        [$lat, $lng] = $this->location($driver, $payload);

        // 'amount' is what THIS signature covers; 'total' is everything signed after it.
        $this->trips->event($trip, 'custody_received', $actor, null, [
            'amount' => $toSign, 'total' => round($signed + $toSign, 2), 'signature' => $path, 'top_up' => $signed > 0,
        ], $lat, $lng, $at);

        return ['amount' => $toSign];
    }
}
