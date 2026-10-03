<?php

namespace App\Services\Fuel;

use App\Models\CompanySetting;
use App\Models\FuelEntry;
use App\Models\TripExpense;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  El Tara — FuelAnalysis (fuel economy per truck and per month)
//  Location: app/Services/Fuel/FuelAnalysis.php
//
//  Scope §6.10:
//    per truck   km/L against the truck's standard, flagging POSSIBLE
//                OVER-DRAW (more than the company's % — 7% by default —
//                below standard)
//    per month   litres, cost, the fleet's average km/L, and the share
//                paid by the company fuel card
//  The arithmetic is in FuelMath (odometer to odometer). One query
//  for the month's refuels, one for each truck's refuel before the
//  month, one for the trucks — however many trucks there are.
//  analyse() is also what the dashboard's "abnormal fuel consumption"
//  action (Step 7) will count.
// ══════════════════════════════════════════════════════════════════

final class FuelAnalysis
{
    public static function flagPercent(int $companyId): float
    {
        return (float) (CompanySetting::for($companyId)->fuel_flag_percent ?? 7);
    }

    /**
     * @return array{
     *   totals: array{fills:int, litres:float, cost:float, card_cost:float, card_share:?float, km:int, kmpl:?float, flagged:int, percent:float},
     *   trucks: list<array>, entries: list<array>
     * }
     */
    public function analyse(Carbon $from, Carbon $to, int $companyId, ?int $vehicleId = null): array
    {
        $percent = self::flagPercent($companyId);

        $fills = FuelEntry::query()
            ->whereBetween('filled_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->with(['vehicle:id,plate_number,plate_letters,std_km_per_litre', 'driver:id,name', 'trip:id,number'])
            ->orderBy('filled_at')->orderBy('id')->limit(5001)->get();

        // One row more than the cap tells us the period was cut (never silently).
        $truncated = $fills->count() > 5000;
        $fills = $fills->take(5000)->values();

        // The odometer of each truck's refuel just before the period starts.
        $previous = FuelEntry::query()
            ->whereIn('id', FuelEntry::query()->selectRaw('MAX(id)')->where('filled_at', '<', $from->format('Y-m-d H:i:s'))->whereNotNull('odometer_km')->groupBy('vehicle_id'))
            ->pluck('odometer_km', 'vehicle_id');

        $economy = [];
        $trucks = [];

        foreach ($fills->groupBy('vehicle_id') as $id => $rows) {
            $vehicle = $rows->first()->vehicle;
            $standard = $vehicle?->std_km_per_litre !== null ? (float) $vehicle->std_km_per_litre : null;

            $perFill = FuelMath::perFill(
                $rows->map(fn (FuelEntry $f) => ['id' => $f->id, 'odometer' => $f->odometer_km, 'litres' => $f->litres])->values()->all(),
                isset($previous[$id]) ? (int) $previous[$id] : null,
                $standard,
                $percent,
            );
            foreach ($perFill as $r) {
                $economy[$r['id']] = $r;
            }

            $summary = FuelMath::summary($perFill, $rows->pluck('litres', 'id')->all(), $standard, $percent);

            $trucks[] = [
                'id'       => (int) $id,
                'number'   => $vehicle?->plate_number,
                'letters'  => $vehicle?->plate_letters,
                'standard' => $standard,
                'fills'    => $rows->count(),
                'litres'   => round((float) $rows->sum('litres'), 2),
                'cost'     => round((float) $rows->sum('amount'), 2),
                'km'       => $summary['km'],
                'litres_km' => $summary['litres'],
                'kmpl'     => $summary['kmpl'],
                'variance' => $summary['variance'],
                'flagged'  => $summary['flagged'],
            ];
        }

        usort($trucks, fn ($a, $b) => [$b['flagged'], $b['cost']] <=> [$a['flagged'], $a['cost']]);

        $entries = $fills->sortByDesc(fn (FuelEntry $f) => $f->filled_at->timestamp * 100000 + $f->id)->map(function (FuelEntry $f) use ($economy) {
            $e = $economy[$f->id] ?? ['km' => null, 'kmpl' => null, 'flagged' => false, 'bad' => false];

            return [
                'id'               => $f->id,
                'filled_at'        => $f->filled_at?->toIso8601String(),
                'vehicle'          => ['id' => $f->vehicle_id, 'number' => $f->vehicle?->plate_number, 'letters' => $f->vehicle?->plate_letters],
                'driver'           => $f->driver?->name,
                'trip'             => $f->trip ? ['id' => $f->trip->id, 'number' => $f->trip->number] : null,
                'linked_expense'   => $f->trip_expense_id !== null,
                'litres'           => $f->litres,
                'price_per_litre'  => $f->price_per_litre,
                'amount'           => $f->amount,
                'litres_estimated' => $f->litres_estimated,
                'odometer_km'      => $f->odometer_km,
                'station'          => $f->station,
                'paid_by'          => $f->paid_by,
                'note'             => $f->note,
                'km'               => $e['km'],
                'kmpl'             => $e['kmpl'],
                'flagged'          => $e['flagged'],
                'bad_odometer'     => $e['bad'],
            ];
        })->values()->all();

        $cost = round((float) $fills->sum('amount'), 2);
        $card = round((float) $fills->where('paid_by', 'card')->sum('amount'), 2);
        $km = array_sum(array_column($trucks, 'km'));
        $litresWithKm = array_sum(array_column($trucks, 'litres_km'));

        return [
            'truncated' => $truncated,
            'totals' => [
                'fills'      => $fills->count(),
                'litres'     => round((float) $fills->sum('litres'), 2),
                'cost'       => $cost,
                'card_cost'  => $card,
                'card_share' => $cost > 0 ? round($card / $cost * 100, 1) : null,
                'km'         => (int) $km,
                'kmpl'       => $litresWithKm > 0 ? round($km / $litresWithKm, 2) : null,
                'flagged'    => count(array_filter($trucks, fn ($t) => $t['flagged'])),
                'percent'    => $percent,
            ],
            'trucks'  => $trucks,
            'entries' => $entries,
        ];
    }

    /**
     * Fuel spent on trips (an expense in the "fuel" category) that has no litres
     * or odometer yet — the office completes it with the refuel form.
     */
    public function waiting(int $limit = 100): array
    {
        $rows = TripExpense::query()
            ->where('is_personal', false)
            ->whereHas('category', fn ($q) => $q->where('code', 'fuel'))
            ->whereHas('trip', fn ($q) => $q->where('is_hired', false)->where('status', '!=', 'cancelled'))
            ->whereNotIn('id', FuelEntry::query()->whereNotNull('trip_expense_id')->select('trip_expense_id'))
            ->with(['trip:id,number,vehicle_id,driver_id', 'trip.vehicle:id,plate_number,plate_letters', 'trip.driver:id,name'])
            ->orderByDesc('spent_at')->limit($limit)->get();

        return $rows->map(fn (TripExpense $e) => [
            'id'        => $e->id,
            'trip'      => ['id' => $e->trip_id, 'number' => $e->trip?->number],
            'vehicle'   => ['id' => $e->trip?->vehicle_id, 'number' => $e->trip?->vehicle?->plate_number, 'letters' => $e->trip?->vehicle?->plate_letters],
            'driver'    => $e->trip?->driver?->name,
            'amount'    => $e->amount,
            'paid_by'   => $e->paid_from === 'company' ? 'card' : 'custody',
            'spent_at'  => $e->spent_at?->toIso8601String(),
            'note'      => $e->note,
        ])->values()->all();
    }
}
