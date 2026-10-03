<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Services\Trips\TripRuleException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ConfirmCollectionHandler (entry "collection.confirm")
//  Location: app/Sync/Handlers/ConfirmCollectionHandler.php
//
//  Two-sided cash (Scope §9): the client recorded that he paid the
//  driver; the driver confirms he really received it. Only then does
//  the amount count in his collections wallet.
// ══════════════════════════════════════════════════════════════════

final class ConfirmCollectionHandler extends TripEntryHandler
{
    public function __construct(private readonly CollectionService $collections) {}

    public function rules(): array
    {
        return ['collection_id' => ['required', 'integer']];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $collection = $this->collection($driver, (int) $payload['collection_id']);

        if ($collection->isOpenDispute() || $collection->resolution === 'cancelled') {
            throw TripRuleException::because('trips.collection_closed');
        }

        $this->collections->confirmByDriver($collection, $actor);

        return ['state' => $collection->refresh()->state()];
    }
}
