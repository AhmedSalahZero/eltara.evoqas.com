<?php

namespace App\Services\Maintenance;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MaintenanceDue (when the next periodic service is due)
//  Location: app/Services/Maintenance/MaintenanceDue.php
//
//  Worked out, never stored. For one completed periodic job:
//    next km   = the odometer at that job + its km interval
//    next date = the day it was completed + its day interval
//  Compared with the truck's odometer today and with today's date:
//    overdue  the odometer has reached the next km, or the date has passed
//    soon     within 500 km or 7 days, and not overdue yet
//    ok       otherwise
//  A job with neither interval has nothing to judge.
//  When several completed jobs exist for the same truck and item,
//  only the latest one (by completion, then by id) counts.
//  Oil, tyres and filters are one item each. "Other" is many different
//  things (brakes, gearbox …), so for it the note says which item it is:
//  a brakes service never hides a gearbox one.
//
//  Pure: no database (tests/Unit/MaintenanceDueTest.php).
// ══════════════════════════════════════════════════════════════════

final class MaintenanceDue
{
    public const SOON_KM = 500;

    public const SOON_DAYS = 7;

    /**
     * The latest completed periodic job for each truck and item.
     *
     * @param  list<array{id?:int, vehicle_id:int, category:string, note?:?string, kind?:string, status?:string, completed_at?:?string}>  $jobs
     * @return list<array<string,mixed>>
     */
    public static function latest(array $jobs): array
    {
        $best = [];

        foreach ($jobs as $job) {
            if (($job['kind'] ?? 'periodic') !== 'periodic' || ($job['status'] ?? 'done') !== 'done') {
                continue;
            }

            $key = $job['vehicle_id'].'|'.$job['category'].'|'.self::itemName($job);
            $current = $best[$key] ?? null;

            if ($current === null || self::isNewer($job, $current)) {
                $best[$key] = $job;
            }
        }

        return array_values($best);
    }

    /**
     * @param  array{kind?:string, status?:string, odometer_km?:?int, completed_on?:?string, interval_km?:?int, interval_days?:?int}  $job
     * @return 'overdue'|'soon'|'ok'|null
     */
    public static function state(array $job, int $currentOdometer, string $today): ?string
    {
        if (($job['kind'] ?? 'periodic') !== 'periodic' || ($job['status'] ?? 'done') !== 'done') {
            return null;
        }

        $next = self::next($job);
        $byKm = self::byKm($next['km'], $currentOdometer);
        $byDate = self::byDate($next['on'], $today);

        return self::worse($byKm, $byDate);
    }

    /**
     * @param  array{odometer_km?:?int, completed_on?:?string, interval_km?:?int, interval_days?:?int}  $job
     * @return array{km:?int, on:?string}
     */
    public static function next(array $job): array
    {
        $km = null;
        if (($job['interval_km'] ?? null) !== null && ($job['odometer_km'] ?? null) !== null) {
            $km = (int) $job['odometer_km'] + (int) $job['interval_km'];
        }

        $on = null;
        if (($job['interval_days'] ?? null) !== null && ! empty($job['completed_on'])) {
            $on = (new \DateTimeImmutable($job['completed_on']))
                ->modify('+'.(int) $job['interval_days'].' days')
                ->format('Y-m-d');
        }

        return ['km' => $km, 'on' => $on];
    }

    /** For "other" the note names the item (same words, any case or spacing, are the same item). */
    private static function itemName(array $job): string
    {
        if (($job['category'] ?? '') !== 'other') {
            return '';
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($job['note'] ?? ''))));
    }

    /** @param array{completed_at?:?string, id?:int} $job */
    private static function isNewer(array $job, array $current): bool
    {
        $at = (string) ($job['completed_at'] ?? '');
        $other = (string) ($current['completed_at'] ?? '');

        if ($at !== $other) {
            return $at > $other;
        }

        return (int) ($job['id'] ?? 0) > (int) ($current['id'] ?? 0);
    }

    private static function byKm(?int $nextKm, int $currentOdometer): ?string
    {
        if ($nextKm === null) {
            return null;
        }

        $left = $nextKm - $currentOdometer;

        if ($left <= 0) {
            return 'overdue';
        }

        return $left <= self::SOON_KM ? 'soon' : 'ok';
    }

    private static function byDate(?string $nextOn, string $today): ?string
    {
        if ($nextOn === null) {
            return null;
        }

        $days = (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($nextOn))->format('%r%a');

        if ($days < 0) {
            return 'overdue';
        }

        return $days <= self::SOON_DAYS ? 'soon' : 'ok';
    }

    private static function worse(?string $a, ?string $b): ?string
    {
        $rank = ['overdue' => 2, 'soon' => 1, 'ok' => 0];

        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $rank[$a] >= $rank[$b] ? $a : $b;
    }
}
