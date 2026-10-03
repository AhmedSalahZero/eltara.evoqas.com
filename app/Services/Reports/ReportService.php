<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\ExpenseCategory;
use App\Models\MonthClose;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\Vehicle;
use App\Models\WalletEntry;
use App\Models\WalletTransfer;
use App\Services\Closing\TrueProfit;
use App\Services\Fuel\FuelAnalysis;
use App\Services\Trips\TripFigures;
use App\Support\DocumentExpiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ReportService (the 12 reports of Scope §6.14)
//  Location: app/Services/Reports/ReportService.php
//
//  One place builds every report, so the screen, the Excel file and
//  the printable page always show the same numbers:
//
//    build($key, $companyId, $filters) → [
//        key, title, columns[ {key,label,type} ], rows[], totals?, note?, truncated
//    ]
//
//  Filters (all optional): from, to (dates), customer_id, vehicle_id,
//  driver_id. The money reports count the trips DELIVERED in the
//  period (as the dashboard does); the wallet reports count what
//  happened in it. Money is never recalculated here — trip revenue /
//  cost come from TripFigures, G&A from TrueProfit (so a closed
//  month's figures are final and an open month's are marked estimate).
//  Column types: text · money · dec (2 decimals) · num · pct · date.
//  Column titles come from lang/*/reports.php.
// ══════════════════════════════════════════════════════════════════

final class ReportService
{
    public const KEYS = ['trips', 'vehicles', 'drivers', 'customers', 'routes', 'wallet', 'transfers', 'close', 'fuel', 'documents', 'no_invoice', 'budget'];

    /** Icons of the hub cards, as on the demo's Reports screen. */
    public const ICONS = [
        'trips' => 'chart', 'vehicles' => 'truck', 'drivers' => 'idcard', 'customers' => 'building', 'routes' => 'route', 'wallet' => 'wallet',
        'transfers' => 'swap', 'close' => 'lock', 'fuel' => 'fuel', 'documents' => 'file', 'no_invoice' => 'link', 'budget' => 'scale',
    ];

    private const LIMIT = 5000;

    /** Set when ANY query of the report being built had more rows than LIMIT (so the report is incomplete). */
    private bool $cut = false;

    public function __construct(private readonly TrueProfit $trueProfit, private readonly FuelAnalysis $fuel) {}

    /** The filters of a request, cleaned: dates (default = this month so far), ids. */
    public static function filters(array $input): array
    {
        $date = function ($v) {
            try {
                return $v ? Carbon::parse($v)->startOfDay() : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $from = $date($input['from'] ?? null) ?? now()->startOfMonth();
        $to = $date($input['to'] ?? null) ?? now()->startOfDay();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }
        $id = fn ($v) => is_numeric($v) && (int) $v > 0 ? (int) $v : null;

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'customer_id' => $id($input['customer_id'] ?? null), 'vehicle_id' => $id($input['vehicle_id'] ?? null), 'driver_id' => $id($input['driver_id'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    public function build(string $key, int $companyId, array $filters): array
    {
        abort_unless(in_array($key, self::KEYS, true), 404);

        $this->cut = false;

        $result = match ($key) {
            'trips'      => $this->trips($companyId, $filters),
            'vehicles'   => $this->vehicles($companyId, $filters),
            'drivers'    => $this->drivers($filters),
            'customers'  => $this->customers($filters),
            'routes'     => $this->routes($filters),
            'wallet'     => $this->wallet($filters),
            'transfers'  => $this->transfers($filters),
            'close'      => $this->close($filters),
            'fuel'       => $this->fuelReport($companyId, $filters),
            'documents'  => $this->documents($filters),
            'no_invoice' => $this->noInvoice($filters),
            'budget'     => $this->budget($companyId, $filters),
        };

        $columns = array_map(fn ($c) => ['key' => $c[0], 'type' => $c[1], 'label' => __('reports.cols.'.$c[0])], $result['columns']);

        return [
            'key'       => $key,
            'title'     => __('reports.items.'.$key.'.title'),
            'columns'   => $columns,
            'rows'      => $result['rows'],
            'totals'    => $result['totals'] ?? null,
            'note'      => $result['note'] ?? null,
            // True when ANY source query was cut at LIMIT — including grouped reports (per truck, per driver …)
            // whose few output rows hide that thousands of trips behind them were dropped.
            'truncated' => $this->cut || ($result['truncated'] ?? false) || count($result['rows']) >= self::LIMIT,
            'filters'   => $filters,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function range(array $f): array
    {
        return [Carbon::parse($f['from'])->startOfDay(), Carbon::parse($f['to'])->endOfDay()];
    }

    /** Gets one row more than LIMIT; if it is there the report is incomplete: remember it and drop the extra row. */
    private function cutAt(Collection $rows): Collection
    {
        if ($rows->count() > self::LIMIT) {
            $this->cut = true;

            return $rows->take(self::LIMIT)->values();
        }

        return $rows;
    }

    /** Delivered / settled trips of the period with their money, filtered. */
    private function deliveredTrips(array $f, array $with = []): Collection
    {
        [$from, $to] = $this->range($f);

        return TripFigures::withMoney(Trip::query()->select('trips.*')->whereIn('trips.status', ['delivered', 'settled'])
            ->whereBetween('trips.delivered_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->when($f['customer_id'], fn ($q, $v) => $q->where('trips.customer_id', $v))
            ->when($f['vehicle_id'], fn ($q, $v) => $q->where('trips.vehicle_id', $v))
            ->when($f['driver_id'], fn ($q, $v) => $q->where('trips.driver_id', $v)))
            ->with($with)->orderBy('trips.delivered_at')->limit(self::LIMIT + 1)->get()->pipe(fn (Collection $r) => $this->cutAt($r));
    }

    private function rev(Trip $t): float
    {
        return TripFigures::rowRevenue($t);
    }

    private function pct(float $part, float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    private function perKm(float $value, float $km): ?float
    {
        return $km > 0 ? round($value / $km, 2) : null;
    }

    /** A translated word, or the raw key when the list has no entry for it. */
    private function word(string $group, ?string $key): string
    {
        $full = 'reports.'.$group.'.'.$key;

        return \Illuminate\Support\Facades\Lang::has($full) ? (string) __($full) : (string) $key;
    }

    private function sum(array $rows, string $key): float
    {
        return round(array_sum(array_map(fn ($r) => (float) ($r[$key] ?? 0), $rows)), 2);
    }

    // ── 1 · Trip profitability ─────────────────────────────────────

    private function trips(int $companyId, array $f): array
    {
        $ctx = $this->trueProfit->context($companyId);
        $trips = $this->deliveredTrips($f, ['customer:id,name_ar,name_en', 'route', 'vehicle:id,plate_number,plate_letters', 'driver:id,name', 'allocations']);

        $rows = $trips->map(function (Trip $t) use ($ctx) {
            $rev = $this->rev($t);
            $cost = round((float) $t->cost_total, 2);
            $profit = round($rev - $cost, 2);
            $ga = $this->trueProfit->forTrip($t, $profit, null, $ctx);

            return [
                'number' => $t->number, 'date' => $t->delivered_at->toDateString(), 'customer' => $t->customer?->displayName(), 'route' => $t->route?->displayName(),
                'vehicle' => $t->is_hired ? __('reports.hired') : trim(($t->vehicle?->plate_number ?? '').' '.($t->vehicle?->plate_letters ?? '')),
                'driver' => $t->driver?->name, 'km' => (int) $t->km, 'revenue' => $rev, 'cost' => $cost, 'profit' => $profit,
                'margin' => $this->pct($profit, $rev), 'profit_km' => $this->perKm($profit, (float) $t->km),
                'ga' => $ga['ga_share'], 'true_profit' => $ga['true_profit'], 'estimate' => $ga['true_profit'] !== null && ! $ga['final'] ? __('reports.yes') : '',
            ];
        })->values()->all();

        $totals = ['km' => (int) $this->sum($rows, 'km'), 'revenue' => $this->sum($rows, 'revenue'), 'cost' => $this->sum($rows, 'cost'), 'profit' => $this->sum($rows, 'profit'), 'ga' => $this->sum($rows, 'ga'), 'true_profit' => $this->sum($rows, 'true_profit')];
        $totals['margin'] = $this->pct($totals['profit'], $totals['revenue']);

        return [
            'columns' => [['number', 'text'], ['date', 'date'], ['customer', 'text'], ['route', 'text'], ['vehicle', 'text'], ['driver', 'text'], ['km', 'num'], ['revenue', 'money'], ['cost', 'money'], ['profit', 'money'], ['margin', 'pct'], ['profit_km', 'dec'], ['ga', 'money'], ['true_profit', 'money'], ['estimate', 'text']],
            'rows' => $rows, 'totals' => $totals,
        ];
    }

    // ── Groupings (vehicles, drivers, customers, routes) ───────────

    /** Rows grouped by one key of the delivered trips, with their money. */
    private function grouped(array $f, callable $key, callable $label, array $with = []): array
    {
        $groups = $this->deliveredTrips($f, $with)->groupBy($key);

        return $groups->map(function (Collection $set) use ($label) {
            $rev = round($set->sum(fn (Trip $t) => $this->rev($t)), 2);
            $cost = round((float) $set->sum('cost_total'), 2);
            $km = (float) $set->sum('km');
            $std = round((float) $set->sum(fn (Trip $t) => array_sum(array_map('floatval', $t->standard_budget ?? []))), 2);

            return [
                'name' => $label($set->first()), 'trips' => $set->count(), 'km' => (int) $km, 'revenue' => $rev, 'cost' => $cost, 'profit' => round($rev - $cost, 2),
                'margin' => $this->pct($rev - $cost, $rev), 'profit_km' => $this->perKm($rev - $cost, $km), 'budget_var' => $std > 0 ? round(($cost - $std) / $std * 100, 1) : null,
                '_set' => $set,
            ];
        })->sortByDesc('profit')->values()->all();
    }

    private function strip(array $rows): array
    {
        return array_map(function ($r) {
            unset($r['_set']);

            return $r;
        }, $rows);
    }

    private function totalsOf(array $rows): array
    {
        $t = ['trips' => (int) $this->sum($rows, 'trips'), 'km' => (int) $this->sum($rows, 'km'), 'revenue' => $this->sum($rows, 'revenue'), 'cost' => $this->sum($rows, 'cost'), 'profit' => $this->sum($rows, 'profit')];
        $t['margin'] = $this->pct($t['profit'], $t['revenue']);
        $t['profit_km'] = $this->perKm($t['profit'], (float) $t['km']);

        return $t;
    }

    // ── 2 · Vehicle profitability ──────────────────────────────────

    private function vehicles(int $companyId, array $f): array
    {
        $rate = TripFigures::gaRate($companyId);
        $rows = $this->grouped($f, fn (Trip $t) => $t->is_hired ? 'hired' : ($t->vehicle_id ?? 0), fn (Trip $t) => $t->is_hired ? __('reports.hired_trucks') : trim(($t->vehicle?->plate_number ?? '—').' '.($t->vehicle?->plate_letters ?? '')), ['vehicle:id,plate_number,plate_letters']);

        foreach ($rows as &$r) {
            $own = $r['_set']->where('is_hired', false)->sum('km');
            $r['ga'] = $rate === null ? null : round($own * $rate, 2);
            $r['true_profit'] = $r['ga'] === null ? null : round($r['profit'] - $r['ga'], 2);
            $r['true_profit_km'] = $r['true_profit'] === null ? null : $this->perKm($r['true_profit'], (float) $r['km']);
        }
        unset($r);
        $rows = $this->strip($rows);

        $totals = $this->totalsOf($rows) + ['ga' => $this->sum($rows, 'ga'), 'true_profit' => $this->sum($rows, 'true_profit')];

        return [
            'columns' => [['name', 'text'], ['trips', 'num'], ['km', 'num'], ['revenue', 'money'], ['cost', 'money'], ['profit', 'money'], ['margin', 'pct'], ['profit_km', 'dec'], ['ga', 'money'], ['true_profit', 'money'], ['true_profit_km', 'dec']],
            'rows' => $rows, 'totals' => $totals, 'note' => $rate === null ? __('reports.no_ga_rate') : __('reports.ga_estimate_note', ['rate' => number_format($rate, 2)]),
        ];
    }

    // ── 3 · Driver performance ─────────────────────────────────────

    private function drivers(array $f): array
    {
        $rows = $this->grouped($f, fn (Trip $t) => $t->driver_id ?? 0, fn (Trip $t) => $t->driver?->name ?? '—', ['driver:id,name']);
        $balances = app(\App\Services\Trips\WalletLedger::class)->manyDriverBalances();

        foreach ($rows as &$r) {
            $id = $r['_set']->first()->driver_id;
            $b = $balances[$id] ?? [];
            $r['holding'] = round(max(0, (float) ($b['custody'] ?? 0)) + max(0, (float) ($b['collections'] ?? 0)), 2);
            $r['advances'] = round(max(0, (float) ($b['advances'] ?? 0)), 2);
        }
        unset($r);
        $rows = $this->strip($rows);

        return [
            'columns' => [['name', 'text'], ['trips', 'num'], ['km', 'num'], ['revenue', 'money'], ['cost', 'money'], ['profit', 'money'], ['profit_km', 'dec'], ['budget_var', 'pct'], ['holding', 'money'], ['advances', 'money']],
            'rows' => $rows, 'totals' => $this->totalsOf($rows), 'note' => __('reports.holding_note'),
        ];
    }

    // ── 4 · Customer profitability ─────────────────────────────────

    private function customers(array $f): array
    {
        $rows = $this->grouped($f, fn (Trip $t) => $t->customer_id, fn (Trip $t) => $t->customer?->displayName() ?? '—', ['customer:id,name_ar,name_en']);
        foreach ($rows as &$r) {
            $r['avg_trip'] = $r['trips'] > 0 ? round($r['revenue'] / $r['trips'], 2) : null;
        }
        unset($r);
        $rows = $this->strip($rows);

        return [
            'columns' => [['name', 'text'], ['trips', 'num'], ['km', 'num'], ['revenue', 'money'], ['cost', 'money'], ['profit', 'money'], ['margin', 'pct'], ['profit_km', 'dec'], ['avg_trip', 'money']],
            'rows' => $rows, 'totals' => $this->totalsOf($rows),
        ];
    }

    // ── 5 · Route profitability ────────────────────────────────────

    private function routes(array $f): array
    {
        $rows = $this->strip($this->grouped($f, fn (Trip $t) => $t->trip_route_id ?? 0, fn (Trip $t) => $t->route?->displayName() ?? '—', ['route']));
        usort($rows, fn ($a, $b) => ($b['profit_km'] ?? -INF) <=> ($a['profit_km'] ?? -INF));

        return [
            'columns' => [['name', 'text'], ['trips', 'num'], ['km', 'num'], ['revenue', 'money'], ['cost', 'money'], ['profit', 'money'], ['margin', 'pct'], ['profit_km', 'dec'], ['budget_var', 'pct']],
            'rows' => $rows, 'totals' => $this->totalsOf($rows),
        ];
    }

    // ── 6 · Driver wallet statement ────────────────────────────────

    private function wallet(array $f): array
    {
        [$from, $to] = $this->range($f);
        $entries = WalletEntry::query()->whereBetween('occurred_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->when($f['driver_id'], fn ($q, $v) => $q->where('driver_id', $v))
            ->when($f['vehicle_id'], fn ($q, $v) => $q->whereIn('trip_id', Trip::query()->where('vehicle_id', $v)->select('id')))
            ->when($f['customer_id'], fn ($q, $v) => $q->whereIn('trip_id', Trip::query()->where('customer_id', $v)->select('id')))
            ->orderBy('occurred_at')->orderBy('id')->limit(self::LIMIT + 1)->get()->pipe(fn (Collection $r) => $this->cutAt($r));

        $drivers = Driver::query()->whereIn('id', $entries->pluck('driver_id')->unique()->all() ?: [0])->pluck('name', 'id');
        $trips = Trip::query()->whereIn('id', $entries->pluck('trip_id')->filter()->unique()->all() ?: [0])->pluck('number', 'id');

        // Opening balance of each wallet of the chosen driver, so the running balance is complete.
        $running = [];
        if ($f['driver_id']) {
            foreach (WalletEntry::query()->where('driver_id', $f['driver_id'])->where('occurred_at', '<', $from->format('Y-m-d H:i:s'))->selectRaw('wallet, SUM(amount) as total')->groupBy('wallet')->pluck('total', 'wallet') as $w => $v) {
                $running[$w] = (float) $v;
            }
        }

        $rows = $entries->map(function (WalletEntry $e) use ($drivers, $trips, &$running, $f) {
            $running[$e->wallet] = round(($running[$e->wallet] ?? 0) + $e->amount, 2);

            return [
                'date' => $e->occurred_at->format('Y-m-d H:i'), 'driver' => $drivers[$e->driver_id] ?? '—', 'trip' => $trips[$e->trip_id] ?? '—',
                'wallet' => $this->word('wallets', $e->wallet), 'type' => $this->word('entry_types', preg_replace('/_correction$/', '', $e->type)).(str_ends_with($e->type, '_correction') ? ' ('.__('reports.correction').')' : ''),
                'amount' => (float) $e->amount, 'balance' => $f['driver_id'] ? $running[$e->wallet] : null, 'note' => $e->note, 'by' => $e->actor_name,
            ];
        })->values()->all();

        $closing = collect($running)->map(fn ($v, $w) => $this->word('wallets', $w).': '.number_format($v, 2))->implode(' · ');

        return [
            'columns' => [['date', 'text'], ['driver', 'text'], ['trip', 'text'], ['wallet', 'text'], ['type', 'text'], ['amount', 'dec'], ['balance', 'dec'], ['note', 'text'], ['by', 'text']],
            'rows' => $rows, 'note' => $f['driver_id'] ? __('reports.closing_balances').' '.$closing : __('reports.pick_driver_for_balance'),
        ];
    }

    // ── 7 · Wallet transfer log ────────────────────────────────────

    private function transfers(array $f): array
    {
        [$from, $to] = $this->range($f);
        $list = WalletTransfer::query()->whereBetween('requested_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->when($f['driver_id'], fn ($q, $v) => $q->where('driver_id', $v))
            ->when($f['vehicle_id'], fn ($q, $v) => $q->whereIn('trip_id', Trip::query()->where('vehicle_id', $v)->select('id')))
            ->when($f['customer_id'], fn ($q, $v) => $q->whereIn('trip_id', Trip::query()->where('customer_id', $v)->select('id')))
            ->with(['trip:id,number', 'driver:id,name', 'decider:id,name'])->orderBy('requested_at')->limit(self::LIMIT + 1)->get()->pipe(fn (Collection $r) => $this->cutAt($r));

        $rows = $list->map(fn (WalletTransfer $t) => [
            'date' => $t->requested_at?->format('Y-m-d H:i'), 'trip' => $t->trip?->number, 'driver' => $t->driver?->name,
            'from' => $this->word('wallets', $t->from_wallet), 'to' => $this->word('wallets', $t->to_wallet), 'amount' => (float) $t->amount,
            'status' => $this->word('transfer_status', $t->status), 'policy' => $t->policy ? $this->word('policies', $t->policy) : '', 'requested_by' => $t->requested_by_name,
            'decided_by' => $t->decider?->name, 'decided_at' => $t->decided_at?->format('Y-m-d H:i'), 'reason' => $t->reason,
        ])->values()->all();

        $moved = array_sum(array_map(fn ($t) => $t->hasMoved() ? $t->amount : 0, $list->all()));

        return [
            'columns' => [['date', 'text'], ['trip', 'text'], ['driver', 'text'], ['from', 'text'], ['to', 'text'], ['amount', 'dec'], ['status', 'text'], ['policy', 'text'], ['requested_by', 'text'], ['decided_by', 'text'], ['decided_at', 'text'], ['reason', 'text']],
            'rows' => $rows, 'note' => __('reports.moved_total', ['amount' => number_format($moved, 2)]),
        ];
    }

    // ── 8 · Month close report ─────────────────────────────────────

    private function close(array $f): array
    {
        $first = Carbon::parse($f['from'])->startOfMonth()->toDateString();
        $last = Carbon::parse($f['to'])->startOfMonth()->toDateString();

        $rows = MonthClose::query()->where('month', '>=', $first)->where('month', '<=', $last)->with('closer:id,name')->orderBy('month')->get()
            ->map(fn (MonthClose $m) => [
                'month' => substr((string) $m->month, 0, 7), 'status' => $this->word('close_status', ($m->status === 'closed' ? 'closed' : 'open')),
                'trips' => (int) $m->trips_count, 'km' => (float) $m->km, 'revenue' => (float) $m->revenue, 'direct_profit' => (float) $m->direct_profit, 'ga_total' => (float) $m->ga_total,
                'rate' => (float) $m->rate, 'true_profit' => (float) $m->true_profit, 'margin' => $this->pct((float) $m->true_profit, (float) $m->revenue),
                'closed_at' => $m->closed_at?->format('Y-m-d H:i'), 'closed_by' => $m->closer?->name,
            ])->values()->all();

        $t = ['trips' => (int) $this->sum($rows, 'trips'), 'km' => $this->sum($rows, 'km'), 'revenue' => $this->sum($rows, 'revenue'), 'direct_profit' => $this->sum($rows, 'direct_profit'), 'ga_total' => $this->sum($rows, 'ga_total'), 'true_profit' => $this->sum($rows, 'true_profit')];
        $t['margin'] = $this->pct($t['true_profit'], $t['revenue']);

        return [
            'columns' => [['month', 'text'], ['status', 'text'], ['trips', 'num'], ['km', 'num'], ['revenue', 'money'], ['direct_profit', 'money'], ['ga_total', 'money'], ['rate', 'dec'], ['true_profit', 'money'], ['margin', 'pct'], ['closed_at', 'text'], ['closed_by', 'text']],
            'rows' => $rows, 'totals' => $t, 'note' => __('reports.close_note'),
        ];
    }

    // ── 9 · Fuel analysis ──────────────────────────────────────────

    private function fuelReport(int $companyId, array $f): array
    {
        [$from, $to] = $this->range($f);
        $a = $this->fuel->analyse($from, $to, $companyId, $f['vehicle_id']);

        $rows = array_map(fn ($x) => [
            'name' => trim(($x['number'] ?? '—').' '.($x['letters'] ?? '')), 'fills' => $x['fills'], 'litres' => $x['litres'], 'cost' => $x['cost'], 'km' => $x['km'],
            'kmpl' => $x['kmpl'], 'standard' => $x['standard'], 'variance' => $x['variance'], 'flagged' => $x['flagged'] ? __('reports.yes') : '',
        ], $a['trucks']);

        $t = $a['totals'];

        return [
            'columns' => [['name', 'text'], ['fills', 'num'], ['litres', 'dec'], ['cost', 'money'], ['km', 'num'], ['kmpl', 'dec'], ['standard', 'dec'], ['variance', 'pct'], ['flagged', 'text']],
            'truncated' => (bool) ($a['truncated'] ?? false),
            'rows' => $rows, 'totals' => ['fills' => $t['fills'], 'litres' => $t['litres'], 'cost' => $t['cost'], 'km' => $t['km'], 'kmpl' => $t['kmpl']],
            'note' => __('reports.fuel_note', ['p' => $t['percent']]),
        ];
    }

    // ── 10 · Expiring documents ────────────────────────────────────

    private function documents(array $f): array
    {
        // Everything expired, or ending up to 30 days after the end of the period.
        $limit = Carbon::parse($f['to'])->addDays(DocumentExpiry::alertDays())->toDateString();
        $rows = [];

        foreach (Vehicle::query()->where('ownership', 'own')->when($f['vehicle_id'], fn ($q, $v) => $q->whereKey($v))->get() as $v) {
            foreach (Vehicle::DOCUMENTS as $doc => $col) {
                if ($v->$col && $v->$col->toDateString() <= $limit) {
                    $d = DocumentExpiry::describe($v->$col);
                    $rows[] = ['kind' => __('reports.vehicle'), 'owner' => $v->plateText(), 'document' => $this->word('docs', $doc), 'expires' => $d['date'], 'days' => $d['days'], 'state' => $this->word('doc_state', $d['state'])];
                }
            }
        }
        foreach (Driver::query()->where('is_active', true)->when($f['driver_id'], fn ($q, $v) => $q->whereKey($v))->whereNotNull('license_expires_at')->whereDate('license_expires_at', '<=', $limit)->get() as $d) {
            $x = DocumentExpiry::describe($d->license_expires_at);
            $rows[] = ['kind' => __('reports.driver'), 'owner' => $d->name, 'document' => __('reports.docs.driving'), 'expires' => $x['date'], 'days' => $x['days'], 'state' => $this->word('doc_state', $x['state'])];
        }
        usort($rows, fn ($a, $b) => $a['days'] <=> $b['days']);

        return [
            'columns' => [['kind', 'text'], ['owner', 'text'], ['document', 'text'], ['expires', 'date'], ['days', 'num'], ['state', 'text']],
            'rows' => $rows, 'note' => __('reports.documents_note', ['date' => $limit]),
        ];
    }

    // ── 11 · Trips without an invoice ──────────────────────────────

    private function noInvoice(array $f): array
    {
        [$from, $to] = $this->range($f);
        $trips = TripFigures::withMoney(Trip::query()->select('trips.*')->where('trips.status', 'settled')->whereNull('trips.invoice_id')
            ->whereBetween('trips.settled_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->when($f['customer_id'], fn ($q, $v) => $q->where('trips.customer_id', $v))
            ->when($f['vehicle_id'], fn ($q, $v) => $q->where('trips.vehicle_id', $v))
            ->when($f['driver_id'], fn ($q, $v) => $q->where('trips.driver_id', $v)))
            ->with(['customer:id,name_ar,name_en', 'route'])->orderBy('trips.settled_at')->limit(self::LIMIT + 1)->get()->pipe(fn (Collection $r) => $this->cutAt($r));

        $rows = $trips->map(fn (Trip $t) => [
            'number' => $t->number, 'customer' => $t->customer?->displayName(), 'route' => $t->route?->displayName(), 'date' => $t->delivered_at?->toDateString(),
            'settled' => $t->settled_at?->toDateString(), 'days' => (int) $t->settled_at->diffInDays(now()), 'revenue' => $this->rev($t),
        ])->values()->all();

        return [
            'columns' => [['number', 'text'], ['customer', 'text'], ['route', 'text'], ['date', 'date'], ['settled', 'date'], ['days', 'num'], ['revenue', 'money']],
            'rows' => $rows, 'totals' => ['revenue' => $this->sum($rows, 'revenue')],
        ];
    }

    // ── 12 · Budget vs actual ──────────────────────────────────────

    private function budget(int $companyId, array $f): array
    {
        $trips = $this->deliveredTrips($f)->where('is_hired', false);
        $cats = ExpenseCategory::query()->get()->keyBy('id');

        $std = [];
        foreach ($trips as $t) {
            foreach (($t->standard_budget ?? []) as $c => $amount) {
                $std[(int) $c] = ($std[(int) $c] ?? 0) + (float) $amount;
            }
        }
        $actual = TripExpense::query()->whereIn('trip_id', $trips->pluck('id')->all() ?: [0])->where('is_personal', false)
            ->selectRaw('expense_category_id, SUM(amount) as total')->groupBy('expense_category_id')->pluck('total', 'expense_category_id');

        $factor = 1 + TripFigures::overBudgetPercent($companyId) / 100;
        $hasBudget = array_sum($std) > 0;
        $rows = collect(array_keys($std))->merge($actual->keys())->unique()->map(function ($id) use ($std, $actual, $cats, $factor, $hasBudget) {
            $s = round((float) ($std[$id] ?? 0), 2);
            $a = round((float) ($actual[$id] ?? 0), 2);
            $unbudgeted = $hasBudget && $s <= 0 && $a > 0 && $cats->get($id)?->code !== 'hire';

            return [
                'category' => $cats->get($id)?->displayName() ?? '—', 'standard' => $s, 'actual' => $a, 'diff' => round($a - $s, 2),
                'var' => $s > 0 ? round(($a - $s) / $s * 100, 1) : null, 'flag' => ($s > 0 && $a > $s * $factor + 0.001) || $unbudgeted ? __('reports.over') : '',
            ];
        })->sortByDesc('actual')->values()->all();

        $t = ['standard' => $this->sum($rows, 'standard'), 'actual' => $this->sum($rows, 'actual'), 'diff' => $this->sum($rows, 'diff')];
        $t['var'] = $t['standard'] > 0 ? round($t['diff'] / $t['standard'] * 100, 1) : null;

        return [
            'columns' => [['category', 'text'], ['standard', 'money'], ['actual', 'money'], ['diff', 'money'], ['var', 'pct'], ['flag', 'text']],
            'rows' => $rows, 'totals' => $t, 'note' => __('reports.budget_note', ['trips' => $trips->count()]),
        ];
    }
}
