<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Base Controller
//  Location: app/Http/Controllers/Controller.php
//
//  Every controller extends this.
//
//  authorizePermission('trips.create')
//      Stops the request with 403 unless the signed-in office user
//      holds the permission key (config/permissions.php). Prefer the
//      route middleware `can:key` for whole routes; use this inside
//      an action when the check depends on the record.
//
//  Deleting and other sensitive actions are recorded with
//  App\Support\Audit::record() — inside the same DB::transaction()
//  as the change, so the change can never happen without its trace.
// ══════════════════════════════════════════════════════════════════

abstract class Controller
{
    protected function authorizePermission(string $key): void
    {
        abort_unless(auth('web')->user()?->can($key) === true, 403, __('errors.forbidden'));
    }
}
