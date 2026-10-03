<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Services\Trips\TripRuleException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DisputeCollectionHandler (entry "collection.dispute")
//  Location: app/Sync/Handlers/DisputeCollectionHandler.php
//
//  "I did not receive this amount" — the driver disputes cash the
//  client recorded. The amount stays out of his wallet until
//  management resolves it (Scope §9).
// ══════════════════════════════════════════════════════════════════

final class DisputeCollectionHandler extends TripEntryHandler
{
    public function __construct(private readonly CollectionService $collections) {}

    public function rules(): array
    {
        return ['collection_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:250']];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $collection = $this->collection($driver, (int) $payload['collection_id']);

        if ($collection->resolved_at !== null) {
            throw TripRuleException::because('trips.collection_closed');
        }

        $this->collections->dispute($collection, 'driver', $actor, $payload['note'] ?? null);

        return ['state' => $collection->refresh()->state()];
    }
}
