<?php

namespace App\Services\Trips;

use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\TripSettlement;
use App\Models\User;
use App\Models\WalletTransfer;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SettlementService (closing a trip's wallets)
//  Location: app/Services/Trips/SettlementService.php
//
//  Scope §6.5. A trip CANNOT be closed until (blockers):
//    · it is delivered with its proof-of-delivery photo;
//    · no wallet transfer is still waiting for approval;
//    · no cash-from-client amount is in an open dispute.
//  Warnings, which the person settling must ACKNOWLEDGE (a tick on the
//  screen, saved with the settlement) before it can go through:
//    · collection amounts the client has not confirmed yet;
//    · amounts the driver has not confirmed (recorded by the client or
//      by the office) — these are not counted in the wallets.
//  The settlement also records who physically received the cash handed in.
//
//  The settlement screen shows, from the ledger:
//    custody balance      + the driver returns it / − refunded to him
//    collections balance  the driver hands it in
//    own-pocket refund    the company pays him back
//    NET = custody + collections − own pocket (Scope §10)
//      + → the driver hands over this amount now
//      − → the company pays the driver this amount
//
//  Settling writes one ledger row PER WALLET (Scope §6.5 "each wallet
//  stays clear") that brings each trip wallet to zero, one
//  trip_settlements record, marks the trip settled and audits it.
//  The advances wallet is not settled here — advances are deducted
//  from salary (Step 6).
// ══════════════════════════════════════════════════════════════════

final class SettlementService
{
    public function __construct(
        private readonly WalletLedger $ledger,
        private readonly TripService $trips,
    ) {}

    /** Everything the settlement panel shows. */
    public function preview(Trip $trip): array
    {
        $balances = $trip->driver_id ? $this->ledger->tripBalances($trip->id) : ['custody' => 0.0, 'collections' => 0.0, 'advances' => 0.0, 'pocket' => 0.0];

        $collections = TripCollection::query()->where('trip_id', $trip->id)->get();
        $pendingTransfers = WalletTransfer::query()->where('trip_id', $trip->id)->where('status', 'pending')->count();
        $disputes = $collections->filter->isOpenDispute();
        $awaitingClient = $collections->filter(fn (TripCollection $c) => $c->state() === 'awaiting_client');
        $awaitingDriver = $collections->filter(fn (TripCollection $c) => $c->state() === 'awaiting_driver');

        $blockers = [];
        if (! $trip->reached('delivered')) {
            $blockers[] = ['code' => 'not_delivered'];
        } elseif (! $trip->pod_path) {
            $blockers[] = ['code' => 'no_pod'];
        }
        if ($pendingTransfers) {
            $blockers[] = ['code' => 'pending_transfers', 'n' => $pendingTransfers];
        }
        if ($disputes->isNotEmpty()) {
            $blockers[] = ['code' => 'open_disputes', 'n' => $disputes->count(), 'amount' => round($disputes->sum('amount'), 2)];
        }

        $warnings = [];
        if ($awaitingClient->isNotEmpty()) {
            $warnings[] = ['code' => 'awaiting_client', 'n' => $awaitingClient->count(), 'amount' => round($awaitingClient->sum('amount'), 2)];
        }
        if ($awaitingDriver->isNotEmpty()) {
            $warnings[] = ['code' => 'awaiting_driver', 'n' => $awaitingDriver->count(), 'amount' => round($awaitingDriver->sum('amount'), 2)];
        }

        return [
            'custody'     => $balances['custody'],
            'collections' => $balances['collections'],
            'pocket'      => $balances['pocket'],
            'net'         => round($balances['custody'] + $balances['collections'] - $balances['pocket'], 2),
            'blockers'    => $blockers,
            'warnings'    => $warnings,
            'can_settle'  => $trip->status === 'delivered' && $blockers === [],
            'unconfirmed' => round($awaitingClient->sum('amount'), 2),
            'needs_ack'   => $awaitingClient->isNotEmpty() || $awaitingDriver->isNotEmpty(),
        ];
    }

    public function settle(Trip $trip, User $user, ?string $note = null, ?string $receivedBy = null, bool $acknowledged = false): TripSettlement
    {
        if ($trip->status !== 'delivered') {
            throw TripRuleException::because($trip->isSettled() ? 'trips.already_settled' : 'trips.not_delivered');
        }

        return DB::transaction(function () use ($trip, $user, $note, $receivedBy, $acknowledged) {
            // Read again inside the transaction, with the trip locked, so two
            // people pressing "Settle" together cannot both settle it.
            $trip = Trip::query()->lockForUpdate()->findOrFail($trip->id);
            if ($trip->status !== 'delivered') {
                throw TripRuleException::because('trips.already_settled');
            }

            $p = $this->preview($trip);
            if (! $p['can_settle']) {
                throw TripRuleException::because('trips.settle_blocked');
            }
            if ($p['needs_ack'] && ! $acknowledged) {
                throw TripRuleException::because('trips.settle_unconfirmed');
            }

            // Who physically took the cash: named by the settler, else the settler himself.
            $receivedBy = $p['net'] > 0 ? mb_substr(trim((string) $receivedBy) ?: $user->name, 0, 120) : null;

            $settlement = TripSettlement::query()->create([
                'company_id'          => $trip->company_id,
                'trip_id'             => $trip->id,
                'driver_id'           => $trip->driver_id,
                'custody_balance'     => $p['custody'],
                'collections_balance' => $p['collections'],
                'pocket_balance'      => $p['pocket'],
                'net_amount'          => $p['net'],
                'unconfirmed_amount'  => $p['unconfirmed'],
                'note'                => $note ? mb_substr($note, 0, 250) : null,
                'cash_received_by'    => $receivedBy,
                'settled_by'          => $user->id,
                'settled_at'          => now(),
            ]);

            if ($trip->driver_id) {
                $actor = Actor::user($user);
                $post = fn (string $wallet, string $type, float $amount) => $this->ledger->post(
                    $trip->company_id, $trip->driver_id, $trip->id, $wallet, $type, $amount, $settlement, $actor, $note);

                $post('custody', $p['custody'] >= 0 ? 'custody_returned' : 'custody_refunded', -$p['custody']);
                $post('collections', 'collections_handed_in', -$p['collections']);
                $post('pocket', 'pocket_refunded', -$p['pocket']);
            }

            $trip->forceFill(['status' => 'settled', 'settled_at' => now(), 'settled_by' => $user->id])->save();
            $this->trips->event($trip, 'settled', Actor::user($user), $note, ['net' => $p['net']]);

            Audit::record('trip.settled', $trip, ['after' => [
                'custody' => $p['custody'], 'collections' => $p['collections'], 'pocket' => $p['pocket'],
                'net' => $p['net'], 'unconfirmed' => $p['unconfirmed'], 'acknowledged_unconfirmed' => $p['needs_ack'], 'cash_received_by' => $receivedBy,
            ]]);

            return $settlement;
        });
    }
}
