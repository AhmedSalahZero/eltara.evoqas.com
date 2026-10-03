<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Services\Trips\TripRuleException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — RecordCollectionHandler (Driver App entry "trip.collection")
//  Location: app/Sync/Handlers/RecordCollectionHandler.php
//
//  The driver received cash from the client (Scope §9). It counts in
//  his collections wallet at once; the client then confirms or
//  disputes it in the client portal.
// ══════════════════════════════════════════════════════════════════

final class RecordCollectionHandler extends TripEntryHandler
{
    public function __construct(private readonly CollectionService $collections) {}

    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'integer'],
            'amount'  => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'note'    => ['nullable', 'string', 'max:250'],
            'photo'   => ['nullable', 'uuid'],
        ];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $trip = $this->trip($driver, (int) $payload['trip_id']);

        $photo = $this->photo($driver, $payload['photo'] ?? null);
        if (! $photo && $this->photoRequired($driver)) {
            // Scope §8.2: the receipt the client signed.
            throw TripRuleException::because('trips.receipt_required');
        }

        $collection = $this->collections->record($trip, (float) $payload['amount'], 'driver', $actor, $payload['note'] ?? null, $at, $photo);

        return ['collection_id' => $collection->id, 'state' => $collection->state()];
    }
}
