<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Sync\SyncHandler;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Offline entry "driver.preferences"
//  Location: app/Sync/Handlers/DriverPreferencesHandler.php
//
//  The driver changed language or dark/light mode on the phone —
//  possibly with no signal. The phone applies it at once and queues
//  this entry; when it uploads, the choice is saved on the driver's
//  account so it follows them to another phone.
//
//  It is also the first real user of the offline sync, so the whole
//  path (phone queue → upload → no duplicates) is proven from Step 1.
//  Trip entries (expenses, cash, delivery …) follow the same pattern
//  in Steps 3–4.
// ══════════════════════════════════════════════════════════════════

final class DriverPreferencesHandler implements SyncHandler
{
    public function rules(): array
    {
        return [
            'language' => ['sometimes', 'in:ar,en'],
            'theme'    => ['sometimes', 'in:dark,light'],
        ];
    }

    public function handle(Driver $driver, array $payload, ?\DateTimeInterface $recordedAt): array
    {
        $driver->forceFill(array_intersect_key($payload, array_flip(['language', 'theme'])))->save();

        return ['language' => $driver->language, 'theme' => $driver->preferredTheme()];
    }
}
