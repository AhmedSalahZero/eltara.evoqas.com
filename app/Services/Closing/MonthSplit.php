<?php

namespace App\Services\Closing;

use DateTimeImmutable;
use DateTimeInterface;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MonthSplit (which month do a trip's km belong to?)
//  Location: app/Services/Closing/MonthSplit.php
//
//  Scope §6.13 step 5 and the company setting "rule for trips
//  spanning two months":
//    hours     split the km between the months by the HOURS the trip
//              spent in each (recommended)
//    start     the whole trip belongs to the month it started in
//    delivery  the whole trip belongs to the month it ended in
//  A trip inside one month is simply all in that month.
//
//  Pure arithmetic on dates — it knows nothing about the database,
//  so it is easy to test on its own (tests/Unit/MonthSplitTest.php).
//  The parts always add up to exactly the trip's km.
//  Month keys look like "2026-09".
// ══════════════════════════════════════════════════════════════════

final class MonthSplit
{
    public const RULES = ['hours', 'start', 'delivery'];

    /** The first moment of the month this moment falls in (same time zone). */
    public static function monthStart(DateTimeInterface $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at->format('Y-m-01 00:00:00'), $at->getTimezone());
    }

    public static function monthKey(DateTimeInterface $at): string
    {
        return $at->format('Y-m');
    }

    /**
     * @return array<string, array{km: float, hours: float}>  month key => its part, in time order
     */
    public static function parts(DateTimeInterface $start, DateTimeInterface $end, float $km, string $rule = 'hours'): array
    {
        $s = DateTimeImmutable::createFromInterface($start);
        $e = DateTimeImmutable::createFromInterface($end)->setTimezone($s->getTimezone());

        if ($e < $s) {
            $e = $s;
        }

        $totalSeconds = $e->getTimestamp() - $s->getTimestamp();
        $totalHours = round($totalSeconds / 3600, 2);
        $startKey = $s->format('Y-m');
        $endKey = $e->format('Y-m');

        // Inside one month, or a rule that puts the whole trip in one month.
        if ($startKey === $endKey || $totalSeconds <= 0 || $rule !== 'hours') {
            $key = $rule === 'delivery' ? $endKey : $startKey;

            return [$key => ['km' => round($km, 2), 'hours' => $totalHours]];
        }

        // Seconds spent in each month, walking from month start to month start.
        $seconds = [];
        $cursor = $s;
        for ($guard = 0; $cursor < $e && $guard < 60; $guard++) {
            $nextMonth = self::monthStart($cursor)->modify('first day of next month');
            $segmentEnd = $nextMonth < $e ? $nextMonth : $e;
            $key = $cursor->format('Y-m');
            $seconds[$key] = ($seconds[$key] ?? 0) + ($segmentEnd->getTimestamp() - $cursor->getTimestamp());
            $cursor = $segmentEnd;
        }

        $parts = [];
        $sum = 0.0;
        foreach ($seconds as $key => $sec) {
            $part = round($km * $sec / $totalSeconds, 2);
            $parts[$key] = ['km' => $part, 'hours' => round($sec / 3600, 2)];
            $sum += $part;
        }

        // Rounding leftovers go to the biggest part, so the total is exactly the trip's km.
        $diff = round($km - $sum, 2);
        if (abs($diff) > 0.001) {
            $biggest = array_keys($seconds, max($seconds), true)[0];
            $parts[$biggest]['km'] = round($parts[$biggest]['km'] + $diff, 2);
        }

        return $parts;
    }
}
