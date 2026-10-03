<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\WalletEntry;
use App\Models\WalletTransfer;
use App\Services\Trips\TransferService;
use App\Services\Trips\WalletLedger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\WalletController ( /office/wallets )
//  Location: app/Http/Controllers/Office/WalletController.php
//
//  "Wallets & settlement" (Scope §6.4): the company's cash outside
//  the safe, and the four lists where the office acts on it:
//    tiles  → custody, collections and advances in drivers' hands,
//             own-pocket money owed back to drivers, what waits
//    tabs   → pending   transfers waiting for approval (approve /
//                       reject — within your approval limit)
//             review    automatic transfers not yet reviewed
//             settle    delivered trips waiting for settlement
//             balances  every driver's wallets
//  Every list is one grouped query, so the screen stays fast with
//  hundreds of drivers. Permission: wallet_transfers.view.
// ══════════════════════════════════════════════════════════════════

class WalletController extends Controller
{
    public const TABS = ['pending', 'review', 'settle', 'balances'];

    public function index(Request $request, WalletLedger $ledger, TransferService $transfers): Response
    {
        $user = $request->user('web');
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'pending';

        $totals = WalletEntry::query()->selectRaw('wallet, SUM(amount) as total')->groupBy('wallet')->pluck('total', 'wallet');
        $pending = WalletTransfer::query()->where('status', 'pending');
        $review = WalletTransfer::query()->where('status', 'auto')->whereNull('reviewed_at');
        $toSettle = Trip::query()->where('status', 'delivered');

        $load = fn ($q) => $q->with(['trip:id,number,trip_route_id,status', 'trip.route:id,origin_ar,origin_en,destination_ar,destination_en', 'driver:id,name', 'decider:id,name'])
            ->orderBy('requested_at')->limit(200)->get()
            ->map(fn (WalletTransfer $t) => TripController::transferRow($t, $user, $transfers) + [
                'trip'   => ['id' => $t->trip_id, 'number' => $t->trip?->number, 'route' => $t->trip?->route?->displayName()],
                'driver' => $t->driver?->name,
            ])->values();

        return Inertia::render('Office/Wallets/Index', [
            'tab'     => $tab,
            'tiles'   => [
                'custody'        => round((float) ($totals['custody'] ?? 0), 2),
                'collections'    => round((float) ($totals['collections'] ?? 0), 2),
                'advances'       => round((float) ($totals['advances'] ?? 0), 2),
                'pocket'         => round((float) ($totals['pocket'] ?? 0), 2),
                'pending'        => (clone $pending)->count(),
                'pending_amount' => round((float) (clone $pending)->sum('amount'), 2),
                'review'         => (clone $review)->count(),
                'review_amount'  => round((float) (clone $review)->sum('amount'), 2),
                'settle'         => (clone $toSettle)->count(),
                'unconfirmed'    => round((float) TripCollection::query()->whereNull('client_confirmed_at')->whereNull('resolution')
                    ->whereHas('trip', fn ($q) => $q->open())->sum('amount'), 2),
                'limit'          => $user->isCompanyAdmin() ? null : ($user->approval_limit !== null ? (float) $user->approval_limit : 0.0),
            ],
            'pending'  => $tab === 'pending' ? $load($pending) : [],
            'review'   => $tab === 'review' ? $load($review) : [],
            'settle'   => $tab === 'settle' ? $this->toSettle($toSettle) : [],
            'balances' => $tab === 'balances' ? $this->balances($ledger) : [],
        ]);
    }

    /** Delivered trips with what each driver hands over — one ledger query for all. */
    private function toSettle($query): array
    {
        $trips = $query->with(['driver:id,name', 'customer:id,name_ar,name_en', 'route:id,origin_ar,origin_en,destination_ar,destination_en', 'vehicle:id,plate_number,plate_letters'])
            ->orderBy('delivered_at')->limit(200)->get();

        $sums = WalletEntry::query()->whereIn('trip_id', $trips->pluck('id')->all() ?: [0])
            ->selectRaw('trip_id, wallet, SUM(amount) as total')->groupBy('trip_id', 'wallet')->get()->groupBy('trip_id');
        $waiting = WalletTransfer::query()->whereIn('trip_id', $trips->pluck('id')->all() ?: [0])->where('status', 'pending')
            ->selectRaw('trip_id, count(*) as n')->groupBy('trip_id')->pluck('n', 'trip_id');
        $disputed = TripCollection::query()->whereIn('trip_id', $trips->pluck('id')->all() ?: [0])->whereNotNull('disputed_at')->whereNull('resolved_at')
            ->selectRaw('trip_id, count(*) as n')->groupBy('trip_id')->pluck('n', 'trip_id');

        return $trips->map(function (Trip $t) use ($sums, $waiting, $disputed) {
            $w = fn (string $wallet) => round((float) ($sums[$t->id] ?? collect())->firstWhere('wallet', $wallet)?->total, 2);

            return [
                'id'           => $t->id,
                'number'       => $t->number,
                'driver'       => $t->driver?->name,
                'customer'     => $t->customer?->displayName(),
                'route'        => $t->route?->displayName(),
                'vehicle'      => $t->vehicle ? ['number' => $t->vehicle->plate_number, 'letters' => $t->vehicle->plate_letters] : null,
                'delivered_at' => $t->delivered_at?->toIso8601String(),
                'custody'      => $w('custody'),
                'collections'  => $w('collections'),
                'pocket'       => $w('pocket'),
                'net'          => round($w('custody') + $w('collections') - $w('pocket'), 2),
                'blocked'      => ($waiting[$t->id] ?? 0) > 0 || ($disputed[$t->id] ?? 0) > 0 || ! $t->pod_path,
            ];
        })->values()->all();
    }

    /** Every driver with money in any wallet, biggest holders first. */
    private function balances(WalletLedger $ledger): array
    {
        $all = $ledger->manyDriverBalances();
        $open = Trip::query()->open()->whereNotNull('driver_id')->selectRaw('driver_id, count(*) as n')->groupBy('driver_id')->pluck('n', 'driver_id');
        $drivers = Driver::query()->whereIn('id', $all->keys()->merge($open->keys())->unique()->all() ?: [0])
            ->with('vehicle:id,driver_id,plate_number,plate_letters')->get(['id', 'name', 'is_active']);

        return $drivers->map(function (Driver $d) use ($all, $open) {
            $b = $all[$d->id] ?? ['custody' => 0, 'collections' => 0, 'advances' => 0, 'pocket' => 0];

            return [
                'id'          => $d->id,
                'name'        => $d->name,
                'vehicle'     => $d->vehicle ? ['number' => $d->vehicle->plate_number, 'letters' => $d->vehicle->plate_letters] : null,
                'open_trips'  => (int) ($open[$d->id] ?? 0),
                'custody'     => $b['custody'],
                'collections' => $b['collections'],
                'advances'    => $b['advances'],
                'pocket'      => $b['pocket'],
                'held'        => round($b['custody'] + $b['collections'], 2),
            ];
        })->sortByDesc('held')->values()->all();
    }
}
