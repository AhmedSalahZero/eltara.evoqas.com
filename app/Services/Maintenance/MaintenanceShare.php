<?php

namespace App\Services\Maintenance;

use App\Models\ExpenseCategory;
use App\Models\MaintenanceJob;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyDefaults;
use App\Services\Trips\Actor;
use App\Services\Trips\ExpenseService;
use Illuminate\Support\Collection;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MaintenanceShare (a job's cost onto the truck's trips)
//  Location: app/Services/Maintenance/MaintenanceShare.php
//
//  The cost of a service is an INDIRECT OVERHEAD of the truck, carried
//  by the trips it serves. A finished job that shares its cost is a pool
//  on the truck. Later trips take from it once, as a company-paid
//  "maintenance" expense (the driver's wallets do not move).
//  Example: oil change 5,000 EGP serving 10,000 km = 0.5 EGP per km;
//  a 450 km trip carries 225 EGP.
//    · km interval   trip km × (cost ÷ interval) until the pool is gone
//                    (trip km = the route's round-trip km; trips do not
//                    record the truck counter. The counter drives when the
//                    next service is due — MaintenanceDue.)
//    · days only     the trip's share of those days
//    · no interval   the next trip takes the whole amount
//  A settled trip is left as it was. Its share stays in the pool
//  as already consumed. Open trips are rewritten so the numbers
//  still add up to the job.
// ══════════════════════════════════════════════════════════════════

final class MaintenanceShare
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function apply(Vehicle $vehicle, User $user): void
    {
        CompanyDefaults::ensure($vehicle->company_id);
        $categoryId = ExpenseCategory::query()->where('code', 'maint')->value('id');
        if (! $categoryId) {
            return;
        }

        $jobs = MaintenanceJob::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('shares_trips', true)
            ->where('status', 'done')
            ->whereNotNull('cost')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        foreach ($jobs as $job) {
            $this->job($job, (int) $categoryId, $user);
        }
    }

    /**
     * Drop every maintenance share a trip carries. Used when the trip is cancelled,
     * so the money goes back into the pool and the next trip takes it.
     * Must run while the trip is still open: a closed trip's expenses cannot change.
     */
    public function releaseTrip(Trip $trip, User $user): void
    {
        TripExpense::query()
            ->where('trip_id', $trip->id)
            ->whereNotNull('maintenance_job_id')
            ->get()
            ->each(fn (TripExpense $expense) => $this->expenses->delete($expense, Actor::user($user)));
    }

    /** Drop this job's share from trips that are still open. */
    public function release(MaintenanceJob $job, User $user): void
    {
        foreach ($this->openExpenses($job) as $expense) {
            $this->expenses->delete($expense, Actor::user($user));
        }
    }

    private function job(MaintenanceJob $job, int $categoryId, User $user): void
    {
        $frozen = (float) TripExpense::query()
            ->where('maintenance_job_id', $job->id)
            ->whereHas('trip', fn ($q) => $q->where('status', 'settled'))
            ->sum('amount');

        $this->release($job, $user);

        $remaining = round((float) $job->cost - $frozen, 2);
        if ($remaining <= 0) {
            return;
        }

        $trips = Trip::query()
            ->where('vehicle_id', $job->vehicle_id)
            ->whereNotIn('status', ['cancelled', 'settled'])
            ->where('loading_at', '>=', $job->completed_at)
            ->orderBy('loading_at')
            ->orderBy('id')
            ->get();

        if ($job->interval_km === null && $job->interval_days === null) {
            $trip = $trips->first();
            if ($trip) {
                $this->post($trip, $job, $categoryId, $remaining, $user);
            }

            return;
        }

        if ($job->interval_km) {
            $rate = (float) $job->cost / $job->interval_km;
            foreach ($trips as $trip) {
                if ($remaining <= 0.009) {
                    break;
                }
                $share = round(min($remaining, (float) $trip->km * $rate), 2);
                if ($share <= 0) {
                    continue;
                }
                $this->post($trip, $job, $categoryId, $share, $user);
                $remaining = round($remaining - $share, 2);
            }

            return;
        }

        foreach ($trips as $trip) {
            if ($remaining <= 0.009) {
                break;
            }
            $days = $trip->planned_hours ? max((float) $trip->planned_hours / 24, 0.01) : 1.0;
            $share = round(min($remaining, (float) $job->cost * $days / $job->interval_days), 2);
            if ($share <= 0) {
                continue;
            }
            $this->post($trip, $job, $categoryId, $share, $user);
            $remaining = round($remaining - $share, 2);
        }
    }

    private function post(Trip $trip, MaintenanceJob $job, int $categoryId, float $amount, User $user): void
    {
        $expense = $this->expenses->record($trip, [
            'expense_category_id' => $categoryId,
            'paid_from' => 'company',
            'amount' => $amount,
            'note' => __('maintenance.trip_share'),
            'spent_at' => $trip->loading_at,
            'maintenance_job_id' => $job->id,
        ], Actor::user($user));
        $expense->forceFill(['maintenance_job_id' => $job->id])->save();
    }

    /** @return Collection<int, TripExpense> */
    private function openExpenses(MaintenanceJob $job): Collection
    {
        return TripExpense::query()
            ->where('maintenance_job_id', $job->id)
            ->whereHas('trip', fn ($q) => $q->where('status', '!=', 'settled'))
            ->with('trip')
            ->get()
            ->filter(fn (TripExpense $expense) => $expense->trip && $expense->trip->isOpen())
            ->values();
    }
}
