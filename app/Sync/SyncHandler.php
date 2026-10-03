<?php

namespace App\Sync;

use App\Models\Driver;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SyncHandler (the contract for one kind of offline entry)
//  Location: app/Sync/SyncHandler.php
//
//  Each kind of entry the Driver App can record offline has one
//  handler class (listed in config/sync.php). The handler:
//    · checks the entry's data (rules());
//    · records it (handle()) — this runs inside a database
//      transaction together with the duplicate-protection receipt,
//      so an entry is recorded exactly once or not at all;
//    · returns a small result the phone keeps (e.g. the new id).
//
//  To refuse an entry for a business reason (e.g. the trip is
//  already closed), throw App\Sync\SyncRejected with a message the
//  driver can read. Refused entries are shown on the phone and never
//  retried automatically.
// ══════════════════════════════════════════════════════════════════

interface SyncHandler
{
    /** Laravel validation rules for the entry's payload. */
    public function rules(): array;

    /**
     * @param  array<string, mixed>  $payload  already validated
     * @param  \Carbon\CarbonImmutable|null  $recordedAt  when the driver did it (phone clock)
     * @return array<string, mixed>  kept on the phone as the result
     */
    public function handle(Driver $driver, array $payload, ?\DateTimeInterface $recordedAt): array;
}
