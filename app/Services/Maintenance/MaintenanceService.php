<?php

namespace App\Services\Maintenance;

use App\Models\CompanySetting;
use App\Models\MaintenanceJob;
use App\Models\MaintenanceJobLine;
use App\Models\StockMove;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Stock\StockService;
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
//  A finished job's cost is shared onto the truck's later trips
//  (MaintenanceShare). An own workshop draws that cost from stock.
//  Every change is audited.
// ══════════════════════════════════════════════════════════════════

final class MaintenanceService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly MaintenanceShare $shares,
    ) {}

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

            $this->syncLines($job, $data);
            $this->afterWrite($job, null, false);
            $this->pool($job, false, $user);
            Audit::record('maintenance.created', $job, ['after' => $this->view($job, $vehicle)]);

            return $job;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(MaintenanceJob $job, array $data, User $user): MaintenanceJob
    {
        return DB::transaction(function () use ($job, $data, $user) {
            $previousVehicleId = (int) $job->vehicle_id;
            $wasOpen = $job->status === 'open';
            $wasDone = $job->status === 'done';
            $before = $this->view($job, $job->vehicle()->first());

            // A job that drew parts from the company workshop and is now recorded as an
            // outside job must put those parts back first. Otherwise they stay issued to a
            // job that no longer owns them, and the store loses stock for nothing.
            if ($job->source === 'own' && $this->source($data, $job) !== 'own') {
                $this->stock->reverseJob($job);
                $this->shares->release($job, $user);
            }

            $vehicle = $this->vehicle((int) ($data['vehicle_id'] ?? $job->vehicle_id));
            $job->fill(['vehicle_id' => $vehicle->id, ...$this->fields($data, $job)])->save();
            $this->syncLines($job, $data);

            // Parts already issued follow the job to its new truck.
            if ($previousVehicleId !== (int) $vehicle->id) {
                StockMove::query()->where('maintenance_job_id', $job->id)->update(['vehicle_id' => $vehicle->id]);
            }

            $this->afterWrite($job, $previousVehicleId, $wasOpen);
            $this->pool($job, $wasDone, $user);
            if ($previousVehicleId !== (int) $job->vehicle_id) {
                $previous = Vehicle::query()->find($previousVehicleId);
                if ($previous) {
                    $this->shares->apply($previous, $user);
                }
            }
            Audit::record('maintenance.updated', $job, ['before' => $before, 'after' => $this->view($job, $vehicle)]);

            return $job;
        });
    }

    public function delete(MaintenanceJob $job, User $user): void
    {
        DB::transaction(function () use ($job, $user) {
            $wasOpen = $job->status === 'open';
            $vehicleId = (int) $job->vehicle_id;
            $vehicle = Vehicle::query()->find($vehicleId);

            Audit::record('maintenance.deleted', $job, ['before' => $this->view($job, $job->vehicle()->first())]);
            $this->stock->reverseJob($job);
            $this->shares->release($job, $user);
            $job->delete();
            if ($vehicle) {
                $this->shares->apply($vehicle, $user);
            }

            if ($wasOpen) {
                $vehicle = Vehicle::query()->find($vehicleId);
                if ($vehicle) {
                    $this->release($vehicle);
                }
            }
        });
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function source(array $data, ?MaintenanceJob $existing): string
    {
        $companyId = $existing?->company_id;
        if (! $companyId && ! empty($data['vehicle_id'])) {
            $companyId = Vehicle::query()->whereKey($data['vehicle_id'])->value('company_id');
        }
        $hasWorkshop = $companyId && CompanySetting::for((int) $companyId)->has_workshop;
        $source = (string) ($data['source'] ?? 'external');

        if ($source === 'own' && ! $hasWorkshop) {
            throw TripRuleException::because('maintenance.own_needs_workshop');
        }
        if (! $hasWorkshop || ! in_array($source, MaintenanceJob::SOURCES, true)) {
            return 'external';
        }

        return $source;
    }

    /** @return list<array{item_id:int, qty:float}> */
    private function lines(array $data): array
    {
        $out = [];
        foreach ($data['lines'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $qty = is_numeric($line['qty'] ?? null) ? round((float) $line['qty'], 3) : 0.0;
            $id = (int) ($line['item_id'] ?? 0);
            if ($id > 0 && $qty > 0) {
                $out[] = ['item_id' => $id, 'qty' => $qty];
            }
        }

        return $out;
    }

    private function syncLines(MaintenanceJob $job, array $data): void
    {
        if ($job->lines()->whereNotNull('stock_move_id')->exists()) {
            return;
        }
        $job->lines()->delete();
        if ($job->source !== 'own') {
            return;
        }
        foreach ($this->lines($data) as $line) {
            MaintenanceJobLine::query()->create([
                'company_id' => $job->company_id,
                'maintenance_job_id' => $job->id,
                'stock_item_id' => $line['item_id'],
                'qty' => $line['qty'],
            ]);
        }
    }

    /** Issue stock when an own job is finished, then share the cost onto trips. */
    private function pool(MaintenanceJob $job, bool $wasDone, User $user): void
    {
        if ($wasDone && $job->status !== 'done') {
            $this->stock->reverseJob($job);
            $this->shares->release($job, $user);
            $job->forceFill(['shares_trips' => false, 'cost' => $job->source === 'own' ? null : $job->cost])->save();
        } elseif ($job->status === 'done') {
            if ($job->source === 'own' && $job->lines()->whereNull('stock_move_id')->exists()) {
                $job->forceFill(['cost' => $this->stock->issueJob($job, $user), 'shares_trips' => true])->save();
            } else {
                $job->forceFill(['shares_trips' => true])->save();
            }
        }

        $vehicle = $job->vehicle()->first();
        if ($vehicle && $job->shares_trips) {
            $this->shares->apply($vehicle, $user);
        }
    }

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
    private function fields(array $data, ?MaintenanceJob $existing = null): array
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
        $source = $this->source($data, $existing);
        $issued = $existing && $existing->lines()->whereNotNull('stock_move_id')->exists();
        $cost = $source === 'own' ? ($issued ? $existing->cost : null) : $this->number($data['cost'] ?? null);
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
            if ($completedAt->greaterThan(now()->addDay())) {
                throw TripRuleException::because('maintenance.completed_in_future');
            }
            if ($source === 'external' && $cost === null) {
                throw TripRuleException::because('maintenance.cost_required');
            }
            if ($source === 'own' && ! $issued && $this->lines($data) === []) {
                throw TripRuleException::because('maintenance.lines_required');
            }
            if ($source === 'own' && ! $issued && empty($data['store_id'])) {
                throw TripRuleException::because('stock.store_required');
            }
            if ($kind === 'periodic' && $intervalKm === null && $intervalDays === null) {
                throw TripRuleException::because('maintenance.interval_required');
            }
        }

        if ($cost !== null && ($cost < 0 || $cost > 10000000)) {
            throw TripRuleException::because('maintenance.cost_required');
        }

        return [
            'source' => $source,
            'store_id' => $source === 'own' ? ($issued ? $existing->store_id : ((int) ($data['store_id'] ?? 0) ?: null)) : null,
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
