<?php

namespace App\Services\Driver;

use App\Models\CompanySetting;
use App\Models\Driver;
use App\Models\DriverAdvance;
use App\Models\TripEvent;
use App\Models\Vehicle;
use App\Support\DocumentExpiry;
use App\Models\ExpenseCategory;
use App\Models\Trip;
use App\Services\Trips\TripFigures;
use App\Services\Trips\WalletLedger;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DriverSnapshot (what the phone keeps to work offline)
//  Location: app/Services/Driver/DriverSnapshot.php
//
//  GET /driver/api/snapshot. One JSON with everything the driver needs
//  to keep working with no signal: his open trips (with the expenses,
//  cash and transfers already on each), his wallet balances, the expense
//  categories and the company's app settings.
//
//  Scope §8.1: the driver NEVER sees prices, revenue or profit, so the
//  freight price, charges, rate card and margins are not sent at all.
//  Also: the custody the driver has to sign for (with the route's budget
//  breakdown), his open advances, his truck and its documents, and his
//  recently finished trips (Scope §8.2).
//  Both languages of every name are sent so the phone can switch
//  language without a connection.
// ══════════════════════════════════════════════════════════════════

final class DriverSnapshot
{
    public function __construct(private readonly WalletLedger $ledger, private readonly TripFigures $figures) {}

    public function build(Driver $driver): array
    {
        $settings = CompanySetting::for($driver->company_id);

        $trips = Trip::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', Trip::OPEN)
            ->with(['customer', 'route', 'cargoType', 'vehicle', 'expenses.category', 'collections', 'transfers'])
            ->orderByRaw("CASE status WHEN 'on_road' THEN 0 WHEN 'loading' THEN 1 WHEN 'accepted' THEN 2 WHEN 'planned' THEN 3 ELSE 4 END")
            ->orderBy('loading_at')
            ->orderBy('id')
            ->get();

        $categories = ExpenseCategory::query()->where('is_active', true)->ordered()->get();
        $moments = $trips->isEmpty() ? collect() : TripEvent::query()->whereIn('trip_id', $trips->pluck('id'))
            ->whereIn('type', ['custody_received', 'custody_requested'])->get()->groupBy('trip_id');

        $tripBalances = $trips->isEmpty() ? [] : $trips->mapWithKeys(fn (Trip $t) => [$t->id => $this->ledger->tripBalances($t->id)])->all();

        return [
            'server_time' => now()->toIso8601String(),
            'wallets'     => $this->ledger->driverBalances($driver->id),
            'settings'    => [
                'receipt_photo_required' => (bool) $settings->receipt_photo_required,
                'capture_location'       => (bool) $settings->capture_location,
                'max_hours_without_sync' => $settings->max_hours_without_sync,
                'over_budget_percent'    => TripFigures::overBudgetPercent($driver->company_id),
            ],
            'profile'     => $this->profile($driver),
            'advances'    => DriverAdvance::query()->where('driver_id', $driver->id)->where('status', 'open')->orderBy('id')->get()
                ->map(fn (DriverAdvance $a) => ['id' => $a->id, 'amount' => $a->amount, 'reason' => $a->reason, 'monthly_instalment' => $a->monthly_instalment, 'repaid' => $a->repaid_amount, 'remaining' => $a->remaining()])->all(),
            'history'     => $this->history($driver),
            'categories'  => $categories
                ->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'code' => $c->code, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en ?: $c->name_ar, 'icon' => $c->icon])->values()->all(),
            'trips'       => $trips->map(fn (Trip $t) => $this->trip($t, $tripBalances[$t->id] ?? [], $moments[$t->id] ?? collect(), $categories))->values()->all(),
        ];
    }

    private function trip(Trip $t, array $balances, $moments, $categories): array
    {
        return [
            'id'              => $t->id,
            'number'          => $t->number,
            'status'          => $t->status,
            'customer'        => ['name_ar' => $t->customer?->name_ar, 'name_en' => $t->customer?->name_en ?: $t->customer?->name_ar],
            'route'           => $t->route ? [
                'origin_ar' => $t->route->origin_ar, 'origin_en' => $t->route->origin_en ?: $t->route->origin_ar,
                'destination_ar' => $t->route->destination_ar, 'destination_en' => $t->route->destination_en ?: $t->route->destination_ar,
                'weight_tons' => $t->route->weight_tons,
            ] : null,
            'cargo'           => $t->cargoType ? ['name_ar' => $t->cargoType->name_ar, 'name_en' => $t->cargoType->name_en ?: $t->cargoType->name_ar] : null,
            'weight_tons'     => $t->weight_tons,
            'truck'           => $t->vehicle?->plateText(),
            'km'              => $t->km,
            'loading_at'      => $t->loading_at?->toIso8601String(),
            'notes'           => $t->notes,
            'client_pays_cash' => (bool) $t->client_pays_cash,
            'custody_planned' => $t->custody_planned,
            'custody_issued'  => $t->custody_issued_at !== null,
            'custody'         => $this->custody($t, $moments, $categories),
            'transfer_policy' => $t->transfer_policy,
            'auto_transfer_limit' => $t->auto_transfer_limit,
            'delivered_at'    => $t->delivered_at?->toIso8601String(),
            'pod_receiver'    => $t->pod_receiver,
            'wallets'         => $balances,
            'expenses'        => $t->expenses->sortByDesc('spent_at')->values()->map(fn ($e) => [
                'id' => $e->id, 'category_id' => $e->expense_category_id, 'is_personal' => $e->is_personal,
                'paid_from' => $e->paid_from, 'amount' => $e->amount, 'note' => $e->note,
                'spent_at' => $e->spent_at?->toIso8601String(), 'has_receipt' => $e->receipt_path !== null,
            ])->all(),
            'collections'     => $t->collections->sortByDesc('received_at')->values()->map(fn ($c) => [
                'id' => $c->id, 'amount' => $c->amount, 'state' => $c->state(), 'recorded_by' => $c->recorded_by,
                'received_at' => $c->received_at?->toIso8601String(), 'note' => $c->note,
            ])->all(),
            'transfers'       => $t->transfers->sortByDesc('requested_at')->values()->map(fn ($x) => [
                'id' => $x->id, 'amount' => $x->amount, 'status' => $x->status, 'reason' => $x->reason,
                'requested_at' => $x->requested_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** What the driver signs for: the amount handed over, whether he signed, and what it is meant for. */
    private function custody(Trip $t, $moments, $categories): array
    {
        $standard = collect($t->standard_budget ?? [])->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v]);

        $issued = $t->custody_issued_at !== null ? (float) $this->figures->wallets($t)['custody']['issued'] : 0.0;
        $signed = (float) $moments->where('type', 'custody_received')->sum(fn ($e) => (float) ($e->meta['amount'] ?? 0));

        return [
            'issued'    => $issued,
            'signed'    => $signed,
            // Signed for everything handed over so far? A top-up makes this false again.
            'received'  => $issued > 0 && $issued - $signed <= 0.004,
            'requested' => $moments->where('type', 'custody_requested')->count(),
            'budget'    => $categories->filter(fn ($c) => ($standard[$c->id] ?? 0) > 0)
                ->map(fn ($c) => ['category_id' => $c->id, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en ?: $c->name_ar, 'amount' => $standard[$c->id]])->values()->all(),
        ];
    }

    /** The driver's truck and the expiry state of its papers and his licence. */
    private function profile(Driver $driver): array
    {
        $vehicle = Vehicle::query()->with('vehicleType')->where('driver_id', $driver->id)->first();

        return [
            'licence' => DocumentExpiry::describe($driver->license_expires_at),
            'vehicle' => $vehicle ? [
                'plate'     => $vehicle->plateText(),
                'type_ar'   => $vehicle->vehicleType?->name_ar,
                'type_en'   => $vehicle->vehicleType?->name_en ?: $vehicle->vehicleType?->name_ar,
                'documents' => $vehicle->documents(),
            ] : null,
        ];
    }

    /** The last finished trips (history only — no money). */
    private function history(Driver $driver): array
    {
        return Trip::query()->where('driver_id', $driver->id)->where('status', 'settled')->with(['customer', 'route'])
            ->orderByDesc('settled_at')->limit(20)->get()
            ->map(fn (Trip $t) => [
                'id' => $t->id, 'number' => $t->number,
                'customer' => ['name_ar' => $t->customer?->name_ar, 'name_en' => $t->customer?->name_en ?: $t->customer?->name_ar],
                'route'    => $t->route ? ['origin_ar' => $t->route->origin_ar, 'origin_en' => $t->route->origin_en ?: $t->route->origin_ar, 'destination_ar' => $t->route->destination_ar, 'destination_en' => $t->route->destination_en ?: $t->route->destination_ar, 'weight_tons' => $t->route->weight_tons] : null,
                'delivered_at' => $t->delivered_at?->toIso8601String(), 'settled_at' => $t->settled_at?->toIso8601String(),
            ])->all();
    }
}
