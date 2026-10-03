<?php

namespace App\Services\Fuel;

use App\Models\CompanySetting;
use App\Models\ExpenseCategory;
use App\Models\FuelEntry;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trips\Actor;
use App\Services\Trips\ExpenseService;
use App\Services\Trips\TripRuleException;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — FuelService (recording refuels)
//  Location: app/Services/Fuel/FuelService.php
//
//  Scope §6.10. A refuel records litres, price, odometer, station and
//  whether it was paid by the company fuel card or by cash from the
//  driver's custody.
//
//  THE TRIP'S COST IS COUNTED ONCE
//    · a refuel tied to a trip is the SAME money as the trip's fuel
//      expense. If the driver already recorded that expense in the app
//      (cash from custody), the office picks it and adds the litres
//      and odometer to it — nothing new is charged to the trip or to
//      the driver's wallets. If there is no expense yet, recording the
//      refuel creates it through ExpenseService (card → paid by the
//      company, custody → comes out of the driver's custody).
//    · a refuel with no trip (filling up at the depot) is only fuel
//      data: it feeds the fuel analysis, not any trip's cost.
//
//  LITRES, PRICE, AMOUNT  any two are enough:
//    litres + price → amount · litres + amount → price ·
//    amount only → litres = amount ÷ the company's diesel price
//    (marked "estimated", Scope §10).
//
//  Hired trucks do not refuel here (their owner pays). Every change is
//  audited. Deleting a refuel never deletes the trip expense.
// ══════════════════════════════════════════════════════════════════

final class FuelService
{
    public const MAX_LITRES = 2000;

    public function __construct(private readonly ExpenseService $expenses) {}

    /**
     * @param  array{vehicle_id?:int, driver_id?:?int, trip_id?:?int, trip_expense_id?:?int, filled_at?:mixed, litres?:mixed,
     *               price_per_litre?:mixed, amount?:mixed, odometer_km?:?int, station?:?string, paid_by?:string, note?:?string}  $data
     */
    public function record(array $data, User $user): FuelEntry
    {
        return DB::transaction(function () use ($data, $user) {
            $expense = null;
            $trip = null;

            if (! empty($data['trip_expense_id'])) {
                $expense = TripExpense::query()->with(['trip', 'category'])->find($data['trip_expense_id']);

                if (! $expense || ! $expense->category || $expense->category->code !== 'fuel' || $expense->is_personal) {
                    throw TripRuleException::because('finance.fuel.not_fuel_expense');
                }
                if (FuelEntry::query()->where('trip_expense_id', $expense->id)->exists()) {
                    throw TripRuleException::because('finance.fuel.expense_linked');
                }

                $trip = $expense->trip;
                $data['vehicle_id'] = $trip->vehicle_id;
                $data['driver_id'] = $trip->driver_id;
                $data['trip_id'] = $trip->id;
                $data['amount'] = $expense->amount;
                $data['paid_by'] = $expense->paid_from === 'company' ? 'card' : 'custody';
                $data['filled_at'] = $data['filled_at'] ?? $expense->spent_at;
            } elseif (! empty($data['trip_id'])) {
                $trip = Trip::query()->find($data['trip_id']);
                if (! $trip || $trip->status === 'cancelled') {
                    throw TripRuleException::because('finance.fuel.bad_trip');
                }
                $data['vehicle_id'] = $data['vehicle_id'] ?? $trip->vehicle_id;
                if ((int) $data['vehicle_id'] !== (int) $trip->vehicle_id) {
                    throw TripRuleException::because('finance.fuel.trip_other_truck');
                }
                $data['driver_id'] = $data['driver_id'] ?? $trip->driver_id;
            }

            $vehicle = Vehicle::query()->find($data['vehicle_id'] ?? 0);
            if (! $vehicle) {
                throw TripRuleException::because('finance.fuel.truck_required');
            }
            if ($vehicle->isHired()) {
                throw TripRuleException::because('finance.fuel.hired_no_fuel');
            }

            $paidBy = (string) ($data['paid_by'] ?? '');
            if (! in_array($paidBy, FuelEntry::PAID_BY, true)) {
                throw TripRuleException::because('finance.fuel.paid_by_required');
            }
            if ($paidBy === 'custody' && ! $trip) {
                throw TripRuleException::because('finance.fuel.custody_needs_trip');
            }

            $numbers = $this->numbers($vehicle->company_id, $data, $expense?->amount);
            $filledAt = isset($data['filled_at']) ? Carbon::parse($data['filled_at']) : Carbon::now();

            // No expense yet on a trip: create it, so the trip's cost and the driver's wallets are right.
            if ($trip && ! $expense) {
                $category = ExpenseCategory::query()->where('code', 'fuel')->first();
                if (! $category) {
                    throw TripRuleException::because('trips.category_required');
                }

                $expense = $this->expenses->record($trip, [
                    'expense_category_id' => $category->id,
                    'paid_from'           => $paidBy === 'card' ? 'company' : 'custody',
                    'amount'              => $numbers['amount'],
                    'note'                => $data['station'] ?? null,
                    'spent_at'            => $filledAt,
                ], Actor::user($user));
            }

            $entry = FuelEntry::query()->create([
                'company_id'       => $vehicle->company_id,
                'vehicle_id'       => $vehicle->id,
                'driver_id'        => $data['driver_id'] ?? null,
                'trip_id'          => $trip?->id,
                'trip_expense_id'  => $expense?->id,
                'filled_at'        => $filledAt,
                'litres'           => $numbers['litres'],
                'price_per_litre'  => $numbers['price'],
                'amount'           => $numbers['amount'],
                'litres_estimated' => $numbers['estimated'],
                'odometer_km'      => $this->odometer($data['odometer_km'] ?? null),
                'station'          => $this->text($data['station'] ?? null, 120),
                'paid_by'          => $paidBy,
                'note'             => $this->text($data['note'] ?? null, 250),
                'created_by'       => $user->id,
            ]);

            $this->touchOdometer($vehicle, $entry->odometer_km);
            Audit::record('fuel.created', $entry, ['after' => $this->view($entry, $vehicle)]);

            return $entry;
        });
    }

    /** Changes the details of a refuel. The amount is locked while it belongs to a trip expense. */
    public function update(FuelEntry $entry, array $data, User $user): FuelEntry
    {
        return DB::transaction(function () use ($entry, $data, $user) {
            $vehicle = $entry->vehicle()->first();
            $before = $this->view($entry, $vehicle);

            $fixedAmount = $entry->trip_expense_id ? $entry->amount : null;
            $numbers = $this->numbers($entry->company_id, $data + ['amount' => $fixedAmount], $fixedAmount);

            $entry->fill([
                'filled_at'        => isset($data['filled_at']) ? Carbon::parse($data['filled_at']) : $entry->filled_at,
                'litres'           => $numbers['litres'],
                'price_per_litre'  => $numbers['price'],
                'amount'           => $numbers['amount'],
                'litres_estimated' => $numbers['estimated'],
                'odometer_km'      => $this->odometer($data['odometer_km'] ?? null),
                'station'          => $this->text($data['station'] ?? null, 120),
                'note'             => $this->text($data['note'] ?? null, 250),
            ])->save();

            if ($vehicle) {
                $this->touchOdometer($vehicle, $entry->odometer_km);
            }
            Audit::record('fuel.updated', $entry, ['before' => $before, 'after' => $this->view($entry, $vehicle)]);

            return $entry;
        });
    }

    public function delete(FuelEntry $entry, User $user): void
    {
        Audit::record('fuel.deleted', $entry, ['before' => $this->view($entry, $entry->vehicle()->first())]);
        $entry->delete();
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Litres, price and amount from whichever two (or one) the person typed.
     *
     * @return array{litres: float, price: ?float, amount: float, estimated: bool}
     */
    private function numbers(int $companyId, array $data, ?float $fixedAmount = null): array
    {
        $litres = $this->number($data['litres'] ?? null);
        $price = $this->number($data['price_per_litre'] ?? null);
        $amount = $fixedAmount ?? $this->number($data['amount'] ?? null);
        $estimated = false;

        if ($litres !== null && $price !== null && $amount === null) {
            $amount = round($litres * $price, 2);
        } elseif ($litres !== null && $amount !== null) {
            $price = round($amount / $litres, 2);
        } elseif ($amount !== null && $litres === null) {
            $price = $price ?? (float) CompanySetting::for($companyId)->diesel_price;
            $litres = $price > 0 ? round($amount / $price, 2) : null;
            $estimated = true;
        } elseif ($litres !== null && $amount === null) {
            $price = (float) CompanySetting::for($companyId)->diesel_price;
            $amount = round($litres * $price, 2);
        }

        if ($litres === null || $amount === null || $litres <= 0 || $amount <= 0) {
            throw TripRuleException::because('finance.fuel.litres_or_amount');
        }
        if ($litres > self::MAX_LITRES) {
            throw TripRuleException::because('finance.fuel.too_many_litres', ['max' => self::MAX_LITRES]);
        }

        return ['litres' => round($litres, 2), 'price' => $price !== null ? round($price, 2) : null, 'amount' => round($amount, 2), 'estimated' => $estimated];
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    private function odometer(mixed $value): ?int
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (int) $value;
    }

    private function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** The truck's odometer only ever goes up. */
    private function touchOdometer(Vehicle $vehicle, ?int $odometer): void
    {
        if ($odometer !== null && $odometer > (int) $vehicle->odometer_km) {
            $vehicle->forceFill(['odometer_km' => $odometer])->save();
        }
    }

    private function view(FuelEntry $entry, ?Vehicle $vehicle): array
    {
        return [
            'truck'    => $vehicle?->plateText(),
            'litres'   => $entry->litres,
            'amount'   => $entry->amount,
            'odometer' => $entry->odometer_km,
            'paid_by'  => $entry->paid_by,
            'trip_id'  => $entry->trip_id,
        ];
    }
}
