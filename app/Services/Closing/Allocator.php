<?php

namespace App\Services\Closing;

use App\Models\CompanySetting;
use App\Models\Trip;
use App\Models\TripAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Allocator (a trip's km, month by month)
//  Location: app/Services/Closing/Allocator.php
//
//  Scope §6.13. G&A is spread over kilometres, so every trip needs
//  to say how many of its km belong to which month.
//
//  THE TRIP'S SPAN
//    start = when it left (else when loading started, else the planned
//            loading time)
//    end   = when it was delivered. A trip still on the road has no end
//            yet: it is ESTIMATED as start + the route's usual hours (or
//            "now" if it is already later than that).
//    Trips that have not left yet (planned / accepted / loading) and
//    cancelled trips have no km in any month.
//
//  THE SPLIT   MonthSplit, by the company's rule (hours / start / delivery).
//
//  FROZEN PARTS  When a month is closed its parts are saved
//  (trip_allocations) and never change. Later, if the trip's real end
//  differs from the estimate, the months still OPEN take whatever km
//  are left, in proportion to their hours — so the trip's parts always
//  add up to exactly its km, and a closed month is never reopened just
//  because a trip finished later than expected.
//
//  Hired trucks carry no G&A (their cost is the owner's fee) unless
//  the company chose the basis "all km" in Company settings.
// ══════════════════════════════════════════════════════════════════

final class Allocator
{
    /** Trips in these states have been on the road. */
    public const STARTED = ['on_road', 'delivered', 'settled'];

    public static function rule(int $companyId): string
    {
        $rule = CompanySetting::for($companyId)->month_split_rule;

        return in_array($rule, MonthSplit::RULES, true) ? $rule : 'hours';
    }

    /** Does a hired truck's km take part? Only with the basis "all km". */
    public static function includesHired(int $companyId): bool
    {
        return CompanySetting::for($companyId)->ga_basis === 'all_km';
    }

    /** @return array{start: Carbon, end: Carbon, estimated: bool}|null */
    public static function span(Trip $trip, ?Carbon $now = null): ?array
    {
        if (! in_array($trip->status, self::STARTED, true)) {
            return null;
        }

        $start = $trip->departed_at ?? $trip->loading_started_at ?? $trip->loading_at;
        if (! $start) {
            return null;
        }

        if ($trip->delivered_at) {
            $end = $trip->delivered_at->copy();
            $estimated = false;
        } else {
            $now = $now ?? Carbon::now();
            $hours = $trip->planned_hours > 0 ? $trip->planned_hours : 24;
            $planned = $start->copy()->addMinutes((int) round($hours * 60));
            $end = $planned->greaterThan($now) ? $planned : $now->copy();
            $estimated = true;
        }

        if ($end->lessThan($start)) {
            $end = $start->copy();
        }

        return ['start' => $start->copy(), 'end' => $end, 'estimated' => $estimated];
    }

    /**
     * The trip's parts by month, ignoring what is already frozen.
     *
     * @return array<string, array{km: float, hours: float}>
     */
    public function ideal(Trip $trip, string $rule, ?Carbon $now = null): array
    {
        $span = self::span($trip, $now);

        return $span ? MonthSplit::parts($span['start'], $span['end'], (float) $trip->km, $rule) : [];
    }

    /**
     * The trip's parts by month as they stand now: frozen ones as saved,
     * open months sharing the km that are left.
     *
     * @param  Collection<int, TripAllocation>  $frozen  the trip's saved allocations
     * @return array<string, array{km: float, hours: float, frozen: bool, rate: ?float, ga_share: ?float, is_estimate: bool}>
     */
    public function effective(Trip $trip, string $rule, Collection $frozen, ?Carbon $now = null): array
    {
        $ideal = $this->ideal($trip, $rule, $now);
        $saved = $frozen->keyBy(fn (TripAllocation $a) => substr((string) $a->month, 0, 7));

        $keys = array_values(array_unique([...array_keys($ideal), ...$saved->keys()->all()]));
        sort($keys);

        $left = max(0.0, round((float) $trip->km - (float) $saved->sum('km'), 2));
        $open = array_values(array_filter($keys, fn ($k) => ! $saved->has($k) && isset($ideal[$k])));
        $openIdeal = array_sum(array_map(fn ($k) => $ideal[$k]['km'], $open));

        $given = 0.0;
        $out = [];
        foreach ($keys as $key) {
            if ($saved->has($key)) {
                $a = $saved[$key];
                $out[$key] = ['km' => $a->km, 'hours' => $a->hours, 'frozen' => true, 'rate' => $a->rate, 'ga_share' => $a->ga_share, 'is_estimate' => $a->is_estimate];
                continue;
            }
            if (! isset($ideal[$key])) {
                continue;
            }

            $isLast = $key === end($open);
            $km = $isLast
                ? round($left - $given, 2)
                : ($openIdeal > 0 ? round($left * $ideal[$key]['km'] / $openIdeal, 2) : 0.0);
            $given += $km;

            $out[$key] = ['km' => max(0.0, $km), 'hours' => $ideal[$key]['hours'], 'frozen' => false, 'rate' => null, 'ga_share' => null, 'is_estimate' => false];
        }

        return $out;
    }

    /**
     * Trips that have km in the month starting at $monthStart: on the road at
     * some point of it (a rough database filter — the exact km come from effective()).
     */
    public function tripsOverlapping(Carbon $monthStart, bool $includeHired): Builder
    {
        $next = $monthStart->copy()->addMonth();

        return Trip::query()
            ->whereIn('trips.status', self::STARTED)
            ->when(! $includeHired, fn ($q) => $q->where('trips.is_hired', false))
            ->whereRaw('COALESCE(trips.departed_at, trips.loading_started_at, trips.loading_at) < ?', [$next->format('Y-m-d H:i:s')])
            ->where(fn ($q) => $q->whereNull('trips.delivered_at')->orWhere('trips.delivered_at', '>=', $monthStart->format('Y-m-d H:i:s')));
    }
}
