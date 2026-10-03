<?php

namespace App\Services\Trips;

use RuntimeException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripRuleException
//  Location: app/Services/Trips/TripRuleException.php
//
//  Thrown when an action breaks a business rule ("the trip cannot be
//  settled: a transfer is still waiting for approval"). The message is
//  already translated and written for the person using the screen.
//  Office controllers show it as a red message; the Driver App
//  (Step 4) turns it into a refused offline entry.
//
//      throw TripRuleException::because('trips.no_pod');
//      → the text of lang/*/trips.php 'no_pod'
// ══════════════════════════════════════════════════════════════════

class TripRuleException extends RuntimeException
{
    public static function because(string $key, array $replace = []): self
    {
        return new self(__($key, $replace));
    }
}
