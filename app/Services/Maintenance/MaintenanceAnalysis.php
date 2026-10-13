<?php

namespace App\Services\Maintenance;

use App\Models\MaintenanceJob;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MaintenanceAnalysis (the log, what is due, the report)
//  Location: app/Services/Maintenance/MaintenanceAnalysis.php
//
//  The office page and the maintenance report both read from here,
//  so the due list and the report judge a job the same way
//  (MaintenanceDue). Due is the latest completed periodic job for
//  each truck and item, compared with the truck's odometer and today.
//  The report lists the jobs opened in the period. A job's live
//  state is shown only while it is still that latest job.
// ══════════════════════════════════════════════════════════════════

final class MaintenanceAnalysis
{
    private const LIMIT = 5000;

    /**
     * @return array{totals: array{open:int, due:int, jobs:int, cost:float}, open: list<array>, due: list<array>, log: list<array>}
     */
    public function screen(Carbon $month, ?int $vehicleId, int $companyId): array
    {
        $open = $this->base($companyId, $vehicleId)->where('status', 'open')->orderByDesc('opened_at')->orderByDesc('id')->get();
        $log = $this->base($companyId, $vehicleId)
            ->whereBetween('opened_at', [$month->copy()->startOfMonth()->format('Y-m-d H:i:s'), $month->copy()->endOfMonth()->format('Y-m-d H:i:s')])
            ->orderByDesc('opened_at')->orderByDesc('id')->limit(500)->get();

        $active = $this->activeIds($companyId, $vehicleId);
        $due = $this->due($companyId, $vehicleId, $active);

        return [
            'totals' => [
                'open' => $open->count(),
                'due' => count($due),
                'jobs' => $log->count(),
                'cost' => round((float) $log->sum(fn (MaintenanceJob $j) => (float) $j->cost), 2),
            ],
            'open' => $open->map(fn (MaintenanceJob $j) => $this->row($j, false))->all(),
            'due' => $due,
            'log' => $log->map(fn (MaintenanceJob $j) => $this->row($j, isset($active[$j->id])))->all(),
        ];
    }

    /**
     * Jobs opened in the period, for the report.
     *
     * @return array{rows: list<array<string,mixed>>, totals: array{amount:float, jobs:int, open:int}, truncated: bool}
     */
    public function period(Carbon $from, Carbon $to, int $companyId, ?int $vehicleId): array
    {
        $jobs = $this->base($companyId, $vehicleId)
            ->whereBetween('opened_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->orderBy('opened_at')->orderBy('id')
            ->limit(self::LIMIT + 1)->get();

        $truncated = $jobs->count() > self::LIMIT;
        if ($truncated) {
            $jobs = $jobs->take(self::LIMIT)->values();
        }

        $active = $this->activeIds($companyId, $vehicleId);
        $rows = $jobs->map(fn (MaintenanceJob $j) => $this->row($j, isset($active[$j->id])))->all();

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'totals' => [
                'amount' => round(array_sum(array_map(fn ($r) => (float) ($r['cost'] ?? 0), $rows)), 2),
                'jobs' => count($rows),
                'open' => count(array_filter($rows, fn ($r) => $r['status'] === 'open')),
            ],
        ];
    }

    /** @return array<int,true> ids of the latest completed periodic job per truck and item */
    private function activeIds(int $companyId, ?int $vehicleId): array
    {
        $jobs = $this->base($companyId, $vehicleId)
            ->where('kind', 'periodic')->where('status', 'done')
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->get(['id', 'vehicle_id', 'category', 'kind', 'status', 'completed_at']);

        $latest = MaintenanceDue::latest($jobs->map(fn (MaintenanceJob $j) => [
            'id' => $j->id,
            'vehicle_id' => $j->vehicle_id,
            'category' => $j->category,
            'kind' => $j->kind,
            'status' => $j->status,
            'completed_at' => $j->completed_at?->format('Y-m-d H:i:s') ?? '',
        ])->all());

        return array_fill_keys(array_map(fn ($j) => (int) $j['id'], $latest), true);
    }

    /** @param array<int,true> $active @return list<array<string,mixed>> */
    private function due(int $companyId, ?int $vehicleId, array $active): array
    {
        if ($active === []) {
            return [];
        }

        $rows = $this->base($companyId, $vehicleId)->whereIn('id', array_keys($active))->get()
            ->map(fn (MaintenanceJob $j) => $this->row($j, true))
            ->filter(fn ($r) => in_array($r['state'], ['overdue', 'soon'], true))
            ->values()->all();

        usort($rows, function ($a, $b) {
            $rank = ['overdue' => 0, 'soon' => 1];

            return [$rank[$a['state']] ?? 9, $a['vehicle']['number'] ?? ''] <=> [$rank[$b['state']] ?? 9, $b['vehicle']['number'] ?? ''];
        });

        return $rows;
    }

    private function base(int $companyId, ?int $vehicleId)
    {
        return MaintenanceJob::query()
            ->where('company_id', $companyId)
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->with(['vehicle:id,plate_number,plate_letters,odometer_km', 'lines:id,maintenance_job_id,stock_item_id,qty,amount'])
            ->withSum('expenses', 'amount');
    }

    /** @return array<string,mixed> */
    private function row(MaintenanceJob $job, bool $active): array
    {
        $vehicle = $job->vehicle;
        $schedule = $this->schedule($job);
        $state = null;

        if ($active && $schedule !== null && $vehicle) {
            $state = MaintenanceDue::state($schedule, (int) $vehicle->odometer_km, today()->toDateString());
        }

        return [
            'id' => $job->id,
            'vehicle' => [
                'id' => $vehicle?->id,
                'number' => $vehicle?->plate_number,
                'letters' => $vehicle?->plate_letters,
                'odometer' => $vehicle?->odometer_km,
            ],
            'kind' => $job->kind,
            'category' => $job->category,
            'status' => $job->status,
            'opened_at' => $job->opened_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'odometer_km' => $job->odometer_km,
            'cost' => $job->cost,
            'workshop' => $job->workshop,
            'note' => $job->note,
            'interval_km' => $job->interval_km,
            'interval_days' => $job->interval_days,
            'next_due_km' => $schedule['next_km'] ?? null,
            'next_due_on' => $schedule['next_on'] ?? null,
            'state' => $state,
            'active' => $active && $state !== null,
            'source' => $job->source,
            'store_id' => $job->store_id,
            'lines' => $job->lines->map(fn ($l) => ['item_id' => $l->stock_item_id, 'qty' => $l->qty, 'amount' => $l->amount])->values(),
            'allocated' => round((float) $job->expenses_sum_amount, 2),
            'remaining' => $job->shares_trips && $job->cost !== null ? round((float) $job->cost - (float) $job->expenses_sum_amount, 2) : null,
        ];
    }

    /** @return array{kind:string, status:string, odometer_km:?int, completed_on:?string, interval_km:?int, interval_days:?int, next_km:?int, next_on:?string}|null */
    private function schedule(MaintenanceJob $job): ?array
    {
        if ($job->kind !== 'periodic' || $job->status !== 'done') {
            return null;
        }

        $input = [
            'kind' => $job->kind,
            'status' => $job->status,
            'odometer_km' => $job->odometer_km,
            'completed_on' => $job->completed_at?->toDateString(),
            'interval_km' => $job->interval_km,
            'interval_days' => $job->interval_days,
        ];
        $next = MaintenanceDue::next($input);

        return $input + ['next_km' => $next['km'], 'next_on' => $next['on']];
    }
}
