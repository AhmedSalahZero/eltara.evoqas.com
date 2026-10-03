<?php

namespace App\Support;

use Carbon\CarbonInterface;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DocumentExpiry (is a licence / insurance still valid?)
//  Location: app/Support/DocumentExpiry.php
//
//  One rule for every document with an expiry date (vehicle licence,
//  insurance, technical inspection, driving licence), Scope §6.7:
//    expired  → the date has passed                (red)
//    soon     → it ends within the alert window       (amber, 30 days unless DOCUMENT_ALERT_DAYS says otherwise)
//    ok       → more days left than the window
//    missing  → no date entered
//  describe($date) → ['date' => '2026-10-12', 'days' => 12, 'state' => 'soon']
// ══════════════════════════════════════════════════════════════════

final class DocumentExpiry
{
    /** Used when nothing is set: documents are flagged amber this many days before they end. */
    public const DEFAULT_ALERT_DAYS = 30;

    /** The amber window in days. Change it with DOCUMENT_ALERT_DAYS in .env (config/eltara.php). */
    public static function alertDays(): int
    {
        return max(1, (int) config('eltara.documents.alert_days', self::DEFAULT_ALERT_DAYS));
    }

    public static function describe(?CarbonInterface $date): array
    {
        if (! $date) {
            return ['date' => null, 'days' => null, 'state' => 'missing'];
        }

        $days = (int) today()->diffInDays($date, false);

        return [
            'date'  => $date->toDateString(),
            'days'  => $days,
            'state' => $days < 0 ? 'expired' : ($days <= self::alertDays() ? 'soon' : 'ok'),
        ];
    }

    public static function needsAttention(?CarbonInterface $date): bool
    {
        return in_array(self::describe($date)['state'], ['expired', 'soon'], true);
    }
}
