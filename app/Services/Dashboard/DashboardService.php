<?php

namespace App\Services\Dashboard;

use App\Models\ClientComplaint;
use App\Models\ClientRequest;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\ExpenseCategory;
use App\Models\GaEntry;
use App\Models\MonthClose;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\TripExpense;
use App\Models\Vehicle;
use App\Models\WalletEntry;
use App\Models\WalletTransfer;
use App\Services\Closing\Allocator;
use App\Services\Fuel\FuelAnalysis;
use App\Services\Trips\TripFigures;
use App\Services\Trips\WalletLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DashboardService (Scope §6.1, §10)
//  Location: app/Services/Dashboard/DashboardService.php
//
//  Every figure on the dashboard, from the real trips. One call
//  builds the whole screen (App\Http\Controllers\Office\DashboardController):
//
//    kpis        2 rows of tiles, each with its trend and a comparison
//                with the previous period
//    road        the trips on the road / loading now
//    cash        company cash outside the safe, and who holds it
//    history     12 months of revenue, cost, direct and true profit
//    actions     the action centre (10 counts, each with its screen)
//    panels      cost mix, fleet, pipeline, rankings, budget, month close
//
//  RULES (the same as the Month close screen, so the figures agree):
//   · a month's revenue / cost / km count the trips DELIVERED in it;
//   · true profit = direct profit − G&A: the saved total of a closed
//     month, else (km × the last closed month's rate, or the company's
//     estimate) and then it is marked "estimate";
//   · own-fleet km carry G&A (all km when the basis is "all km");
//   · fleet utilisation = trip hours ÷ available hours of the own fleet
//     (trucks in maintenance are not available);
//   · the truck's place on the road is an ESTIMATE from the time since
//     departure (no live GPS — Scope: location only at key moments).
// ══════════════════════════════════════════════════════════════════

class DashboardService
{
    public const PERIODS = ['m', 'lm', 'q', 'y'];

    /** The colours of the cost donut, by standard category code. */
    private const COST_COLOURS = [
        'fuel' => '#BA7517', 'toll' => '#1B6FB8', 'weigh' => '#5C7999', 'allow' => '#1E7A5C', 'labor' => '#7B5EA7',
        'night' => '#C9A227', 'fine' => '#C0392B', 'repair' => '#E08A3C', 'hire' => '#34495E', 'other' => '#95A5A6',
    ];

    /** @var array<string, Collection> */
    private array $monthCache = [];

    public function __construct(private readonly WalletLedger $ledger, private readonly FuelAnalysis $fuel) {}

    public static function period(?string $value): string
    {
        return in_array($value, self::PERIODS, true) ? $value : 'm';
    }

    /** @return array<string,mixed> */
    public function build(int $companyId, string $period, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy();
        $period = self::period($period);

        $settings = CompanySetting::for($companyId);
        $basisAll = $settings->ga_basis === 'all_km';
        $rate = TripFigures::gaRate($companyId);

        [$from, $to, $prevFrom, $prevTo] = $this->window($period, $now);

        // 12 months of delivered trips, read once.
        $historyFrom = $now->copy()->startOfMonth()->subMonths(11);
        $rows = $this->deliveredRows($historyFrom, $now);
        $closed = MonthClose::query()->where('status', 'closed')->with('closer:id,name')->get()->keyBy(fn ($m) => substr((string) $m->month, 0, 7));

        $history = $this->history($rows, $closed, $historyFrom, $basisAll, $rate);
        $stats = $this->stats($rows, $from, $to, $closed, $basisAll, $rate);
        $prev = $prevFrom ? $this->stats($rows, $prevFrom, $prevTo, $closed, $basisAll, $rate) : null;

        $own = Vehicle::query()->where('ownership', 'own')->get(['id', 'plate_number', 'plate_letters', 'status', 'driver_id', 'std_km_per_litre', 'ownership']);
        $fuel = $this->fuel->analyse($from->copy(), $to->copy(), $companyId)['totals'];
        $stds = $own->pluck('std_km_per_litre')->filter()->map(fn ($v) => (float) $v);

        $util = $this->utilisation($from, $to, $own, $now);
        $trips = $this->periodTrips($from, $to);
        $overIds = $this->overBudgetIds($trips, TripFigures::overBudgetPercent($companyId));
        $balances = $this->ledger->manyDriverBalances();

        return [
            'period'    => $period,
            'now'       => $now->toIso8601String(),
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'kpis'      => $this->kpis($stats, $prev, $history, $rate, $util, $fuel['kmpl'] ?? null, $stds->isNotEmpty() ? round($stds->avg(), 2) : null, $period),
            'road'      => $this->road($companyId, $now),
            'cash'      => $this->cash($balances),
            'history'   => $history,
            'actions'   => $this->actions($companyId, $now, $overIds),
            'costMix'   => $this->costMix($trips),
            'fleet'     => $this->fleet($own, $companyId, $now, $util),
            'pipeline'  => $this->pipeline($from, $to),
            'customers' => $this->byCustomer($trips),
            'routes'    => $this->byRoute($trips),
            'vehicles'  => $this->byVehicle($trips, $rate),
            'drivers'   => $this->byDriver($trips, $balances),
            'attention' => $this->attention($trips, $overIds),
            'budget'    => $this->budget($trips),
            'close'     => $this->closePanel($companyId, $now, $closed),
        ];
    }

    // ── Periods ────────────────────────────────────────────────────

    /** @return array{0:Carbon,1:Carbon,2:?Carbon,3:?Carbon} from, to, previous from, previous to */
    private function window(string $period, Carbon $now): array
    {
        $startThis = $now->copy()->startOfMonth();

        return match ($period) {
            'lm' => [
                $startThis->copy()->subMonth(), $startThis->copy()->subSecond(),
                $startThis->copy()->subMonths(2), $startThis->copy()->subMonth()->subSecond(),
            ],
            'q' => [
                $startThis->copy()->subMonths(2), $now->copy(),
                $startThis->copy()->subMonths(5), $startThis->copy()->subMonths(2)->subSecond(),
            ],
            'y' => [$startThis->copy()->subMonths(11), $now->copy(), null, null],
            // This month to date, compared with the same stretch of last month.
            default => [
                $startThis, $now->copy(),
                $startThis->copy()->subMonth(),
                min($startThis->copy()->subMonth()->addSeconds((int) $startThis->diffInSeconds($now, true)), $startThis->copy()->subSecond()),
            ],
        };
    }

    // ── The numbers ────────────────────────────────────────────────

    /** Light rows of the trips delivered in the window: no models, no N+1. */
    private function deliveredRows(Carbon $from, Carbon $to): Collection
    {
        return TripFigures::withMoney(Trip::query()->select('trips.id', 'trips.km', 'trips.is_hired', 'trips.freight_price', 'trips.delivered_at')
            ->whereIn('trips.status', ['delivered', 'settled'])
            ->where('trips.delivered_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('trips.delivered_at', '<=', $to->format('Y-m-d H:i:s')))
            ->get()
            ->map(fn (Trip $t) => [
                'at'    => $t->delivered_at,
                'key'   => $t->delivered_at->format('Y-m'),
                'km'    => (float) $t->km,
                'hired' => (bool) $t->is_hired,
                'rev'   => TripFigures::rowRevenue($t),
                'cost'  => round((float) $t->cost_total, 2),
            ]);
    }

    /** G&A of one month: the saved total when closed, else km × rate (an estimate), else null. */
    private function gaOf(string $key, float $kmCarrying, Collection $closed, ?float $rate): array
    {
        if ($closed->has($key)) {
            return [(float) $closed[$key]->ga_total, false];
        }

        return [$rate !== null ? round($kmCarrying * $rate, 2) : null, true];
    }

    /** @return list<array<string,mixed>> */
    private function history(Collection $rows, Collection $closed, Carbon $from, bool $basisAll, ?float $rate): array
    {
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $from->copy()->addMonths($i);
            $key = $m->format('Y-m');
            $set = $rows->where('key', $key);
            $rev = round($set->sum('rev'), 2);
            $direct = round($set->sum('cost'), 2);
            $km = round($set->sum('km'), 1);
            $carry = round($set->where('hired', false)->sum('km') + ($basisAll ? $set->where('hired', true)->sum('km') : 0), 1);
            [$ga, $estimate] = $this->gaOf($key, $carry, $closed, $rate);

            $out[] = [
                'key' => $key, 'month' => (int) $m->format('n'), 'rev' => $rev, 'direct' => $direct, 'dp' => round($rev - $direct, 2),
                'ga' => $ga, 'tp' => $ga === null ? null : round($rev - $direct - $ga, 2), 'closed' => ! $estimate,
                'km' => $km, 'km_own' => $carry, 'trips' => $set->count(),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function stats(Collection $rows, Carbon $from, Carbon $to, Collection $closed, bool $basisAll, ?float $rate): array
    {
        $set = $rows->filter(fn ($r) => $r['at']->betweenIncluded($from, $to));
        $rev = round($set->sum('rev'), 2);
        $direct = round($set->sum('cost'), 2);
        $km = round($set->sum('km'), 1);

        // G&A month by month: a closed month counts its saved total only when the window covers it all.
        $ga = 0.0;
        $unknown = false;
        $estimate = false;
        foreach ($set->groupBy('key') as $key => $month) {
            $carry = $month->where('hired', false)->sum('km') + ($basisAll ? $month->where('hired', true)->sum('km') : 0);
            [$g, $est] = $this->gaOf($key, (float) $carry, $closed, $rate);
            $wholeMonth = $from->lessThanOrEqualTo(Carbon::createFromFormat('Y-m-d', $key.'-01')->startOfMonth());
            if (! $est && $wholeMonth) {
                $ga += $g;
            } elseif ($rate !== null) {
                $ga += round($carry * $rate, 2);
                $estimate = true;
            } else {
                $unknown = true;
            }
            $estimate = $estimate || $est;
        }

        return [
            'rev' => $rev, 'direct' => $direct, 'dp' => round($rev - $direct, 2), 'km' => $km, 'trips' => $set->count(),
            'ga' => $unknown ? null : round($ga, 2), 'tp' => $unknown ? null : round($rev - $direct - $ga, 2), 'estimate' => $estimate,
        ];
    }

    private function kpis(array $s, ?array $prev, array $history, ?float $rate, float $util, ?float $kmpl, ?float $standard, string $period): array
    {
        $tail = array_slice($history, -8);
        $spark = fn (string $k) => array_map(fn ($h) => $h[$k] ?? 0, $tail);
        $change = fn (?float $a, ?float $b) => ($b === null || abs($b) < 0.005 || $a === null) ? null : round(($a - $b) / abs($b) * 100, 1);

        return [
            'rev'       => ['value' => $s['rev'], 'change' => $change($s['rev'], $prev['rev'] ?? null), 'spark' => $spark('rev')],
            'direct'    => ['value' => $s['direct'], 'change' => $change($s['direct'], $prev['direct'] ?? null), 'share' => $s['rev'] > 0 ? round($s['direct'] / $s['rev'] * 100, 1) : null, 'spark' => $spark('direct')],
            'dp'        => ['value' => $s['dp'], 'margin' => $s['rev'] > 0 ? round($s['dp'] / $s['rev'] * 100, 1) : null, 'spark' => $spark('dp')],
            'tp'        => ['value' => $s['tp'], 'margin' => $s['tp'] !== null && $s['rev'] > 0 ? round($s['tp'] / $s['rev'] * 100, 1) : null, 'estimate' => $s['estimate'], 'spark' => array_map(fn ($h) => $h['tp'] ?? 0, $tail)],
            'km'        => ['value' => $s['km'], 'change' => $change($s['km'], $prev['km'] ?? null), 'trips' => $s['trips'], 'spark' => $spark('km')],
            'rev_km'    => $s['km'] > 0 ? round($s['rev'] / $s['km'], 2) : null,
            'cost_km'   => $s['km'] > 0 ? round($s['direct'] / $s['km'], 2) : null,
            'ga_km'     => $rate,
            'util'      => round($util, 0),
            'kmpl'      => $kmpl,
            'kmpl_std'  => $standard,
            'compare'   => $prev !== null,
        ];
    }

    /** Trip hours ÷ available hours of the own fleet in the window (Scope §10). */
    private function utilisation(Carbon $from, Carbon $to, Collection $own, Carbon $now): float
    {
        $available = $own->where('status', '!=', 'maintenance')->count();
        $hours = max(1.0, $from->diffInMinutes($to, true) / 60);
        if ($available === 0) {
            return 0.0;
        }

        $trips = Trip::query()->whereIn('trips.status', Allocator::STARTED)->where('trips.is_hired', false)
            ->whereRaw('COALESCE(trips.departed_at, trips.loading_started_at, trips.loading_at) <= ?', [$to->format('Y-m-d H:i:s')])
            ->where(fn ($q) => $q->whereNull('trips.delivered_at')->orWhere('trips.delivered_at', '>=', $from->format('Y-m-d H:i:s')))
            ->get(['id', 'status', 'loading_at', 'loading_started_at', 'departed_at', 'delivered_at', 'planned_hours']);

        $busy = 0.0;
        foreach ($trips as $trip) {
            $span = Allocator::span($trip, $now);
            if (! $span) {
                continue;
            }
            $a = $span['start']->greaterThan($from) ? $span['start'] : $from;
            $b = $span['end']->lessThan($to) ? $span['end'] : $to;
            if ($b->greaterThan($a)) {
                $busy += $a->diffInMinutes($b, true) / 60;
            }
        }

        return min(100.0, $busy / ($available * $hours) * 100);
    }

    // ── On the road right now ──────────────────────────────────────

    /** @return array<string,mixed> */
    private function road(int $companyId, Carbon $now): array
    {
        $live = TripFigures::withMoney(Trip::query()->select('trips.*')->whereIn('trips.status', ['loading', 'on_road']))
            ->with(['driver:id,name', 'vehicle:id,plate_number,plate_letters,ownership', 'customer:id,name_ar,name_en', 'route'])
            ->get();

        $bal = WalletEntry::query()->whereIn('trip_id', $live->pluck('id')->all() ?: [0])
            ->selectRaw('trip_id, wallet, SUM(amount) as total')->groupBy('trip_id', 'wallet')->get()->groupBy('trip_id');
        $pending = WalletTransfer::query()->where('status', 'pending')->whereIn('trip_id', $live->pluck('id')->all() ?: [0])->pluck('trip_id')->unique()->all();
        $over = $this->overBudgetIds($live, TripFigures::overBudgetPercent($companyId));

        $lanes = $live->map(function (Trip $t) use ($bal, $pending, $over, $now) {
            $b = ($bal[$t->id] ?? collect())->pluck('total', 'wallet');
            $custody = round((float) ($b['custody'] ?? 0), 2);
            $coll = round((float) ($b['collections'] ?? 0), 2);

            $progress = 4.0;
            $eta = null;
            if ($t->status === 'on_road') {
                $start = $t->departed_at ?? $now;
                // Expected arrival = departure + half of the route's usual round-trip hours (as the client portal says).
                $end = $start->copy()->addMinutes((int) round(($t->planned_hours ?: 24) * 60 / 2));
                $total = max(60, $start->diffInSeconds($end, true));
                $progress = max(6.0, min(94.0, $start->diffInSeconds($now, true) / $total * 100));
                $eta = max(1, (int) round($now->diffInMinutes($end, false) / 60));
            }

            return [
                'id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'hired' => (bool) $t->is_hired,
                'driver' => $t->driver?->name, 'vehicle' => $t->vehicle ? ['number' => $t->vehicle->plate_number, 'letters' => $t->vehicle->plate_letters] : null,
                'customer' => $t->customer?->displayName(), 'from' => $this->origin($t), 'to' => $this->destination($t),
                'progress' => round($progress, 1), 'eta_hours' => $eta, 'custody' => $t->is_hired ? null : $custody, 'collections' => $coll,
                'warn' => in_array($t->id, $pending, true) || in_array($t->id, $over, true), 'revenue' => TripFigures::rowRevenue($t),
            ];
        })->sortByDesc('progress')->values();

        return [
            'lanes'    => $lanes->all(),
            'active'   => $lanes->count(),
            'revenue'  => round($lanes->sum('revenue'), 2),
            'cash'     => round($lanes->sum(fn ($l) => max(0, (float) $l['custody']) + max(0, (float) $l['collections'])), 2),
            'planned24' => Trip::query()->whereIn('status', ['planned', 'accepted'])->where('loading_at', '<=', $now->copy()->addDay())->where('loading_at', '>=', $now->copy()->subDay())->count(),
        ];
    }

    private function origin(Trip $t): ?string
    {
        $r = $t->route;

        return $r ? (app()->getLocale() === 'en' && $r->origin_en ? $r->origin_en : $r->origin_ar) : null;
    }

    private function destination(Trip $t): ?string
    {
        $r = $t->route;

        return $r ? (app()->getLocale() === 'en' && $r->destination_en ? $r->destination_en : $r->destination_ar) : null;
    }

    // ── Cash outside the safe ──────────────────────────────────────

    private function cash(Collection $balances): array
    {
        $drivers = Driver::query()->whereIn('id', $balances->keys()->all() ?: [0])->get(['id', 'name'])->keyBy('id');
        $openTrips = Trip::query()->whereIn('status', Trip::OPEN)->whereNotNull('driver_id')->selectRaw('driver_id, COUNT(*) as n')->groupBy('driver_id')->pluck('n', 'driver_id');

        $custody = $collections = $advances = 0.0;
        $rows = [];
        foreach ($balances as $driverId => $b) {
            $cu = max(0.0, (float) ($b['custody'] ?? 0));
            $co = max(0.0, (float) ($b['collections'] ?? 0));
            $ad = max(0.0, (float) ($b['advances'] ?? 0));
            $custody += $cu;
            $collections += $co;
            $advances += $ad;
            if ($cu + $co + $ad > 0.004) {
                $rows[] = ['id' => (int) $driverId, 'name' => $drivers->get($driverId)?->name ?? '—', 'trips' => (int) ($openTrips[$driverId] ?? 0), 'amount' => round($cu + $co + $ad, 2)];
            }
        }
        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'total' => round($custody + $collections + $advances, 2), 'custody' => round($custody, 2), 'collections' => round($collections, 2), 'advances' => round($advances, 2),
            'drivers' => array_slice($rows, 0, 4),
        ];
    }

    // ── The action centre ──────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function actions(int $companyId, Carbon $now, array $overIds): array
    {
        $thisMonth = $now->copy()->startOfMonth();
        $limit = $now->copy()->addDays(30)->toDateString();

        $collections = TripCollection::query()->whereNull('resolution');
        $unconfirmed = (clone $collections)->where(fn ($q) => $q->whereNull('driver_confirmed_at')->orWhereNull('client_confirmed_at')->orWhereNotNull('disputed_at'))->count();
        $disputed = (clone $collections)->whereNotNull('disputed_at')->count();

        $docs = 0;
        $expired = 0;
        foreach (['licence_expires_at', 'insurance_expires_at', 'inspection_expires_at'] as $col) {
            $docs += Vehicle::query()->where('ownership', 'own')->whereNotNull($col)->whereDate($col, '<=', $limit)->count();
            $expired += Vehicle::query()->where('ownership', 'own')->whereNotNull($col)->whereDate($col, '<', $now->toDateString())->count();
        }
        $docs += Driver::query()->where('is_active', true)->whereNotNull('license_expires_at')->whereDate('license_expires_at', '<=', $limit)->count();
        $expired += Driver::query()->where('is_active', true)->whereNotNull('license_expires_at')->whereDate('license_expires_at', '<', $now->toDateString())->count();

        $loss = $this->monthTrips($thisMonth, $now)->filter(fn (Trip $t) => TripFigures::rowRevenue($t) - (float) $t->cost_total < 0)->count();
        $overMonth = count(array_intersect($overIds, $this->monthTrips($thisMonth, $now)->pluck('id')->all()));
        $flagged = (int) ($this->fuel->analyse($thisMonth->copy(), $now->copy(), $companyId)['totals']['flagged'] ?? 0);

        $noInvoice = Trip::query()->where('status', 'settled')->whereNull('invoice_id')->where('settled_at', '<=', $now->copy()->subDays(3))->count();

        return [
            ['key' => 'requests', 'colour' => 'am', 'icon' => 'file', 'count' => ClientRequest::query()->where('status', 'new')->count(), 'sub' => ['n' => ClientComplaint::query()->where('status', 'open')->count()], 'route' => 'office.client-requests.index', 'permission' => 'client_requests.view'],
            ['key' => 'collections', 'colour' => 'vi', 'icon' => 'hand', 'count' => $unconfirmed, 'sub' => ['n' => $disputed], 'route' => 'office.wallets.index', 'permission' => 'wallet_transfers.view'],
            ['key' => 'transfers', 'colour' => 'rd', 'icon' => 'swap', 'count' => WalletTransfer::query()->where('status', 'pending')->count(), 'sub' => [], 'route' => 'office.wallets.index', 'permission' => 'wallet_transfers.view'],
            ['key' => 'review', 'colour' => 'am', 'icon' => 'eye', 'count' => WalletTransfer::query()->where('status', 'auto')->whereNull('reviewed_at')->count(), 'sub' => [], 'route' => 'office.wallets.index', 'permission' => 'wallet_transfers.view'],
            ['key' => 'unsettled', 'colour' => 'vi', 'icon' => 'clock', 'count' => Trip::query()->where('status', 'delivered')->count(), 'sub' => [], 'route' => 'office.wallets.index', 'permission' => 'trip_settlement.view'],
            ['key' => 'loss', 'colour' => 'rd', 'icon' => 'alert', 'count' => $loss, 'sub' => [], 'route' => 'office.trips.index', 'permission' => 'trips.view'],
            ['key' => 'over', 'colour' => 'cu', 'icon' => 'flag', 'count' => $overMonth, 'sub' => ['p' => TripFigures::overBudgetPercent($companyId)], 'route' => 'office.trips.index', 'permission' => 'trips.view'],
            ['key' => 'docs', 'colour' => 'am', 'icon' => 'file', 'count' => $docs, 'sub' => ['n' => $expired], 'route' => 'office.vehicles.index', 'permission' => 'vehicles.view'],
            ['key' => 'fuel', 'colour' => 'bl', 'icon' => 'fuel', 'count' => $flagged, 'sub' => ['p' => FuelAnalysis::flagPercent($companyId)], 'route' => 'office.fuel.index', 'permission' => 'fuel.view'],
            ['key' => 'invoices', 'colour' => 'bl', 'icon' => 'link', 'count' => $noInvoice, 'sub' => [], 'route' => 'office.invoices.index', 'permission' => 'invoice_links.view'],
        ];
    }

    /** Trips delivered this month with their money (for the loss / over-budget counts). */
    private function monthTrips(Carbon $monthStart, Carbon $now): Collection
    {
        return $this->monthCache[$monthStart->format('Y-m-d')] ??= TripFigures::withMoney(Trip::query()->select('trips.*')->whereIn('trips.status', ['delivered', 'settled'])
            ->where('trips.delivered_at', '>=', $monthStart->format('Y-m-d H:i:s'))->where('trips.delivered_at', '<=', $now->format('Y-m-d H:i:s')))->get();
    }

    // ── Period trips and their rankings ────────────────────────────

    private function periodTrips(Carbon $from, Carbon $to): Collection
    {
        return TripFigures::withMoney(Trip::query()->select('trips.*')->whereIn('trips.status', ['delivered', 'settled'])
            ->where('trips.delivered_at', '>=', $from->format('Y-m-d H:i:s'))->where('trips.delivered_at', '<=', $to->format('Y-m-d H:i:s')))
            ->with(['customer:id,name_ar,name_en', 'route', 'vehicle:id,plate_number,plate_letters,driver_id,ownership', 'driver:id,name'])
            ->get();
    }

    /** Ids of trips with a budget line more than the company's % over its standard (as the trip page flags it). */
    private function overBudgetIds(Collection $trips, float $percent): array
    {
        if ($trips->isEmpty()) {
            return [];
        }
        $factor = 1 + $percent / 100;
        $actual = TripExpense::query()->whereIn('trip_id', $trips->pluck('id')->all())->where('is_personal', false)
            ->selectRaw('trip_id, expense_category_id, SUM(amount) as total')->groupBy('trip_id', 'expense_category_id')->get()
            ->groupBy('trip_id');

        $ids = [];
        foreach ($trips as $trip) {
            $per = ($actual[$trip->id] ?? collect())->pluck('total', 'expense_category_id');
            foreach (($trip->standard_budget ?? []) as $category => $std) {
                if ((float) $std > 0 && (float) ($per[(int) $category] ?? 0) > (float) $std * $factor + 0.001) {
                    $ids[] = $trip->id;
                    break;
                }
            }
        }

        return $ids;
    }

    private function tripStd(Trip $t): float
    {
        return round(array_sum(array_map('floatval', $t->standard_budget ?? [])), 2);
    }

    private function group(Collection $trips, callable $key): Collection
    {
        return $trips->groupBy($key)->map(function (Collection $set, $k) {
            $rev = $set->sum(fn (Trip $t) => TripFigures::rowRevenue($t));
            $cost = $set->sum(fn (Trip $t) => (float) $t->cost_total);
            $km = (float) $set->sum('km');
            $std = $set->sum(fn (Trip $t) => $this->tripStd($t));

            return [
                'key' => $k, 'trips' => $set->count(), 'rev' => round($rev, 2), 'cost' => round($cost, 2), 'km' => (int) $km, 'profit' => round($rev - $cost, 2),
                'ppk' => $km > 0 ? round(($rev - $cost) / $km, 2) : 0.0, 'var' => $std > 0 ? round(($cost - $std) / $std * 100, 1) : 0.0, 'first' => $set->first(),
            ];
        });
    }

    private function byCustomer(Collection $trips): array
    {
        return $this->group($trips, fn (Trip $t) => $t->customer_id)->sortByDesc('profit')->take(7)->map(fn ($g) => [
            'name' => $g['first']->customer?->displayName(), 'rev' => $g['rev'], 'cost' => $g['cost'], 'profit' => $g['profit'],
        ])->values()->all();
    }

    private function byRoute(Collection $trips): array
    {
        return $this->group($trips->where('is_hired', false), fn (Trip $t) => $t->trip_route_id)->sortByDesc('ppk')->take(7)->map(fn ($g) => [
            'name' => $g['first']->route?->displayName(), 'trips' => $g['trips'], 'ppk' => $g['ppk'], 'var' => $g['var'],
        ])->values()->all();
    }

    private function byVehicle(Collection $trips, ?float $rate): array
    {
        $groups = $this->group($trips->where('is_hired', false)->whereNotNull('vehicle_id'), fn (Trip $t) => $t->vehicle_id)
            ->map(fn ($g) => $g + ['tppk' => round($g['ppk'] - ($rate ?? 0), 2)])->sortByDesc('tppk')->values();

        $row = fn ($g) => [
            'id' => $g['first']->vehicle_id, 'number' => $g['first']->vehicle?->plate_number, 'letters' => $g['first']->vehicle?->plate_letters,
            'driver' => $g['first']->driver?->name, 'trips' => $g['trips'], 'tppk' => $g['tppk'],
        ];

        return [
            'best'  => $groups->take(3)->map($row)->values()->all(),
            'worst' => $groups->count() > 3 ? $groups->slice(-2)->reverse()->map($row)->values()->all() : [],
            'known' => $rate !== null,
        ];
    }

    private function byDriver(Collection $trips, Collection $balances): array
    {
        return $this->group($trips->whereNotNull('driver_id')->where('is_hired', false), fn (Trip $t) => $t->driver_id)->sortByDesc('profit')->take(7)->map(function ($g) use ($balances) {
            $b = $balances[$g['key']] ?? [];

            return [
                'id' => (int) $g['key'], 'name' => $g['first']->driver?->name, 'trips' => $g['trips'], 'km' => $g['km'], 'profit' => $g['profit'], 'var' => $g['var'],
                'holding' => round(max(0, (float) ($b['custody'] ?? 0)) + max(0, (float) ($b['collections'] ?? 0)), 2),
            ];
        })->values()->all();
    }

    /** Loss-making and over-budget trips of the period (at most 8). */
    private function attention(Collection $trips, array $overIds): array
    {
        $loss = $trips->filter(fn (Trip $t) => TripFigures::rowRevenue($t) - (float) $t->cost_total < 0);
        $over = $trips->filter(fn (Trip $t) => in_array($t->id, $overIds, true) && ! $loss->contains('id', $t->id));

        return $loss->map(fn ($t) => ['t' => $t, 'kind' => 'loss'])->concat($over->take(6)->map(fn ($t) => ['t' => $t, 'kind' => 'over']))->take(8)->map(fn ($x) => [
            'id' => $x['t']->id, 'number' => $x['t']->number, 'driver' => $x['t']->driver?->name, 'route' => $x['t']->route?->displayName(),
            'profit' => round(TripFigures::rowRevenue($x['t']) - (float) $x['t']->cost_total, 2), 'kind' => $x['kind'],
        ])->values()->all();
    }

    // ── Costs and budgets ──────────────────────────────────────────

    private function costMix(Collection $trips): array
    {
        $cats = ExpenseCategory::query()->get()->keyBy('id');
        $rows = TripExpense::query()->whereIn('trip_id', $trips->pluck('id')->all() ?: [0])->where('is_personal', false)
            ->selectRaw('expense_category_id, SUM(amount) as total')->groupBy('expense_category_id')->get();

        $parts = $rows->map(fn ($r) => [
            'name' => $cats->get($r->expense_category_id)?->displayName() ?? '—', 'code' => $cats->get($r->expense_category_id)?->code,
            'value' => round((float) $r->total, 2), 'colour' => self::COST_COLOURS[$cats->get($r->expense_category_id)?->code ?? 'other'] ?? '#7A8CA3',
        ])->sortByDesc('value')->values();

        return ['total' => round($parts->sum('value'), 2), 'parts' => $parts->all()];
    }

    private function budget(Collection $trips): array
    {
        $own = $trips->where('is_hired', false);
        $cats = ExpenseCategory::query()->get()->keyBy('id');
        $std = [];
        foreach ($own as $t) {
            foreach (($t->standard_budget ?? []) as $c => $amount) {
                $std[(int) $c] = ($std[(int) $c] ?? 0) + (float) $amount;
            }
        }
        $actual = TripExpense::query()->whereIn('trip_id', $own->pluck('id')->all() ?: [0])->where('is_personal', false)
            ->selectRaw('expense_category_id, SUM(amount) as total')->groupBy('expense_category_id')->pluck('total', 'expense_category_id');

        return collect($std)->keys()->merge($actual->keys())->unique()->map(function ($id) use ($std, $actual, $cats) {
            $s = round((float) ($std[$id] ?? 0), 2);
            $a = round((float) ($actual[$id] ?? 0), 2);

            return ['name' => $cats->get($id)?->displayName() ?? '—', 'std' => $s, 'actual' => $a, 'var' => $s > 0 ? round(($a - $s) / $s * 100, 1) : null];
        })->sortByDesc('actual')->values()->all();
    }

    // ── Fleet, pipeline, month close ───────────────────────────────

    private function fleet(Collection $own, int $companyId, Carbon $now, float $util): array
    {
        $vehicles = Vehicle::query()->get(['id', 'plate_number', 'plate_letters', 'ownership', 'status']);
        $running = Trip::query()->whereIn('status', ['loading', 'on_road'])->pluck('status', 'vehicle_id');
        $next = Trip::query()->whereIn('status', ['planned', 'accepted'])->pluck('vehicle_id')->flip();

        $grid = $vehicles->map(function (Vehicle $v) use ($running, $next) {
            $state = $v->status === 'maintenance' ? 'maint' : (isset($running[$v->id]) ? ($running[$v->id] === 'loading' ? 'loading' : 'road') : (isset($next[$v->id]) ? 'next' : 'idle'));

            return ['id' => $v->id, 'plate' => trim($v->plate_number.' '.$v->plate_letters), 'state' => $state];
        });

        return [
            'total' => $vehicles->count(), 'hired' => $vehicles->where('ownership', 'hired')->count(), 'util' => round($util, 0),
            'counts' => $grid->countBy('state')->all(), 'grid' => $grid->values()->all(),
        ];
    }

    private function pipeline(Carbon $from, Carbon $to): array
    {
        $counts = Trip::query()->whereIn('status', ['planned', 'accepted', 'loading', 'on_road', 'delivered'])->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $settled = Trip::query()->where('status', 'settled')->where('settled_at', '>=', $from->format('Y-m-d H:i:s'))->where('settled_at', '<=', $to->format('Y-m-d H:i:s'));

        return [
            'planned' => (int) ($counts['planned'] ?? 0), 'accepted' => (int) ($counts['accepted'] ?? 0), 'loading' => (int) ($counts['loading'] ?? 0),
            'on_road' => (int) ($counts['on_road'] ?? 0), 'delivered' => (int) ($counts['delivered'] ?? 0),
            'no_invoice' => (clone $settled)->whereNull('invoice_id')->count(), 'invoiced' => (clone $settled)->whereNotNull('invoice_id')->count(),
        ];
    }

    private function closePanel(int $companyId, Carbon $now, Collection $closed): array
    {
        $months = [];
        foreach ([1, 2] as $back) {
            $m = $now->copy()->startOfMonth()->subMonths($back);
            $key = $m->format('Y-m');
            $row = $closed[$key] ?? null;
            $months[] = [
                'key' => $key, 'closed' => (bool) $row, 'closed_by' => $row?->closer?->name, 'closed_at' => $row?->closed_at?->toIso8601String(),
                'lines' => GaEntry::query()->where('month', $key.'-01')->count(), 'ga_total' => round((float) GaEntry::query()->where('month', $key.'-01')->sum('amount'), 2),
            ];
        }
        $last = $closed->sortKeysDesc()->first();

        return [
            'months' => $months,
            'last'   => $last ? ['month' => substr((string) $last->month, 0, 7), 'ga' => (float) $last->ga_total, 'km' => (float) $last->km, 'rate' => (float) $last->rate, 'tp' => (float) $last->true_profit, 'revenue' => (float) $last->revenue] : null,
            'standard_lines' => count(GaEntry::STANDARD),
        ];
    }
}
