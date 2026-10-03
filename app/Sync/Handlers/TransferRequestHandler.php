<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Services\Trips\Actor;
use App\Services\Trips\TransferService;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TransferRequestHandler (Driver App entry "trip.transfer")
//  Location: app/Sync/Handlers/TransferRequestHandler.php
//
//  "Use client money for road costs": moves collections → custody.
//  The trip's policy decides: automatic, or waits for a manager
//  (Scope §6.4). The money moves only when it is approved/automatic.
// ══════════════════════════════════════════════════════════════════

final class TransferRequestHandler extends TripEntryHandler
{
    public function __construct(private readonly TransferService $transfers) {}

    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'integer'],
            'amount'  => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'reason'  => ['required', 'string', 'max:250'],
        ];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $trip = $this->trip($driver, (int) $payload['trip_id']);

        $transfer = $this->transfers->request($trip, (float) $payload['amount'], $payload['reason'], $actor);

        return ['transfer_id' => $transfer->id, 'status' => $transfer->status];
    }
}
