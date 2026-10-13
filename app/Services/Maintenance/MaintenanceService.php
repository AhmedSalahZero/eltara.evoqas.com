<?php

namespace App\Services\Maintenance;

use App\Models\MaintenanceJob;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trips\TripRuleException;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MaintenanceService (recording work on a truck)
//  Location: app/Services/Maintenance/MaintenanceService.php
//
//  An OPEN job puts the truck in maintenance, so it cannot be booked
//  on a new trip (the rule already in TripService). A job recorded
//  already done — history — does not change the truck's status.
//  Completing or deleting the last open job puts the truck back to
//  available. A running trip is left as it is.
//
//  The odometer only ever goes up, as it does for fuel.
//  The cost is workshop data only: no trip expense, no G&A line.
//  Every change is audited.
// ══════════════════════════════════════════════════════════════════

final class MaintenanceService
{
    /**
     * @param  array{vehicle_id?:int, kind?:string, category?:string, status?:string, opened_at?:mixed, completed_at?:mixed,
     *               odometer_km?:mixed, cost?:mixed, workshop?:?string, note?:?string, interval_km?:mixed, interval_days?:mixed}  $data
     */
    public function record(array $data, User $user): MaintenanceJob
    {
        return DB::transaction(function () use ($data, $user) {
            $vehicle = $this->vehicle((int) ($data['vehicle_id'] ?? 0));
            $fields = $this->fields($data);

            $job = MaintenanceJob::query()->create([
                'company_id' => $vehicle->company_id,
                'vehicle_id' => $vehicle->id,
                'created_by' => $user->id,
                ...$fields,
            ]);

            $this->afterWrite($job, null, false);
            Audit::record('maintenance.created', $job, ['after' => $this->view($job, $vehicle)]);

            return $job;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(MaintenanceJob $job, array $data, User $user): MaintenanceJob
    {
        return DB::transaction(function () use ($job, $data) {
            $previousVehicleId = (int) $job->vehicle_id;
            $wasOpen = $job->status === 'open';
            $before = $this->view($job, $job->vehicle()->first());

            $vehicle = $this->vehicle((int) ($data['vehicle_id'] ?? $job->vehicle_id));
            $job->fill(['vehicle_id' => $vehicle->id, ...$this->fields($data)])->save();

            $this->afterWrite($job, $previousVehicleId, $wasOpen);
            Audit::record('maintenance.updated', $job, ['before' => $before, 'after' => $this->view($job, $vehicle)]);

            return $job;
        });
    }

    public function delete(MaintenanceJob $job, User $user): void
    {
        DB::transaction(function () use ($job) {
            $wasOpen = $job->status === 'open';
            $vehicleId = (int) $job->vehicle_id;

            Audit::record('maintenance.deleted', $job, ['before' => $this->view($job, $job->vehicle()->first())]);
            $job->delete();

            if ($wasOpen) {
                $vehicle = Vehicle::query()->find($vehicleId);
                if ($vehicle) {
                    $this->release($vehicle);
                }
            }
        });
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function vehicle(int $id): Vehicle
    {
        $vehicle = Vehicle::query()->find($id);
        if (! $vehicle) {
            throw TripRuleException::because('maintenance.truck_required');
        }

        return $vehicle;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function fields(array $data): array
    {
        $kind = (string) ($data['kind'] ?? '');
        $category = (string) ($data['category'] ?? '');
        $status = (string) ($data['status'] ?? '');

        if (! in_array($kind, MaintenanceJob::KINDS, true) || ! in_array($category, MaintenanceJob::CATEGORIES, true) || ! in_array($status, MaintenanceJob::STATUSES, true)) {
            throw TripRuleException::because('maintenance.bad_choice');
        }

        $openedAt = $this->moment($data['opened_at'] ?? null, 'maintenance.opened_required');
        if ($openedAt->greaterThan(now()->addDay())) {
            throw TripRuleException::because('maintenance.opened_in_future');
        }

        $note = $this->text($data['note'] ?? null, 250);
        $odometer = $this->whole($data['odometer_km'] ?? null, 0);
        $intervalKm = $kind === 'periodic' ? $this->whole($data['interval_km'] ?? null, 1) : null;
        $intervalDays = $kind === 'periodic' ? $this->whole($data['interval_days'] ?? null, 1) : null;
        $cost = $this->number($data['cost'] ?? null);
        $completedAt = null;

        if ($intervalKm !== null && $odometer === null) {
            throw TripRuleException::because('maintenance.odometer_for_interval');
        }
        if (($kind === 'emergency' || $category === 'other') && $note === null) {
            throw TripRuleException::because('maintenance.note_required');
        }

        if ($status === 'done') {
            $completedAt = $this->moment($data['completed_at'] ?? null, 'maintenance.completed_required');
            if ($completedAt->lt($openedAt)) {
                throw TripRuleException::because('maintenance.completed_before_open');
            }
            if ($cost === null) {
                throw TripRuleException::because('maintenance.cost_required');
            }
            if ($kind === 'periodic' && $intervalKm === null && $intervalDays === null) {
                throw TripRuleException::because('maintenance.interval_required');
            }
        }

        if ($cost !== null && ($cost < 0 || $cost > 10000000)) {
            throw TripRuleException::because('maintenance.cost_required');
        }

        return [
            'kind' => $kind,
            'category' => $category,
            'status' => $status,
            'opened_at' => $openedAt,
            'completed_at' => $completedAt,
            'odometer_km' => $odometer,
            'cost' => $cost !== null ? round($cost, 2) : null,
            'workshop' => $this->text($data['workshop'] ?? null, 120),
            'note' => $note,
            'interval_km' => $intervalKm,
            'interval_days' => $intervalDays,
        ];
    }

    /** Open work holds the truck. Closing or moving the last open job releases it. */
    private function afterWrite(MaintenanceJob $job, ?int $previousVehicleId, bool $wasOpen): void
    {
        $vehicle = $job->vehicle()->first();
        if ($vehicle) {
            $this->touchOdometer($vehicle, $job->odometer_km);
            if ($job->status === 'open') {
                $this->hold($vehicle);
            } elseif ($wasOpen && (int) $previousVehicleId === (int) $job->vehicle_id) {
                $this->release($vehicle);
            }
        }

        if ($wasOpen && $previousVehicleId && (int) $previousVehicleId !== (int) $job->vehicle_id) {
            $previous = Vehicle::query()->find($previousVehicleId);
            if ($previous) {
                $this->release($previous);
            }
        }
    }

    private function hold(Vehicle $vehicle): void
    {
        if ($vehicle->status !== 'maintenance') {
            $vehicle->forceFill(['status' => 'maintenance'])->save();
        }
    }

    private function release(Vehicle $vehicle): void
    {
        $vehicle->refresh();
        $open = MaintenanceJob::query()->where('vehicle_id', $vehicle->id)->where('status', 'open')->exists();

        if (! $open && $vehicle->status === 'maintenance') {
            $vehicle->forceFill(['status' => 'available'])->save();
        }
    }

    /** The truck's odometer only ever goes up. */
    private function touchOdometer(Vehicle $vehicle, ?int $odometer): void
    {
        if ($odometer !== null && $odometer > (int) $vehicle->odometer_km) {
            $vehicle->forceFill(['odometer_km' => $odometer])->save();
        }
    }

    private function moment(mixed $value, string $error): Carbon
    {
        if ($value === null || $value === '') {
            throw TripRuleException::because($error);
        }

        return Carbon::parse($value);
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    /** A whole number at or above $min, or null when it was left empty. */
    private function whole(mixed $value, int $min): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $n = (int) $value;

        return $n >= $min ? $n : null;
    }

    private function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function view(MaintenanceJob $job, ?Vehicle $vehicle): array
    {
        return [
            'truck' => $vehicle?->plateText(),
            'kind' => $job->kind,
            'category' => $job->category,
            'status' => $job->status,
            'cost' => $job->cost,
            'odometer' => $job->odometer_km,
        ];
    }
}
