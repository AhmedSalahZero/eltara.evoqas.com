<?php

namespace App\Services\Trips;

use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\User;
use App\Services\Notifier;
use App\Models\WalletTransfer;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TransferService (collections → custody, with approval)
//  Location: app/Services/Trips/TransferService.php
//
//  Scope §6.4. The driver holds client money (collections) and needs
//  it for road costs (custody). The move is always a recorded
//  transfer. What happens to it depends on the TRIP's policy (the
//  company default, changeable per trip):
//
//    approval → always waits for a manager            → pending
//    limit    → automatic up to the trip's limit       → auto
//               (e.g. 1,000 EGP), above it waits       → pending
//    auto     → always automatic (night / long trips)  → auto
//
//  "auto" transfers move the money at once but stay in the
//  "to review" list until someone marks them reviewed.
//
//  Who may approve (Scope §5 "approval limit per user"):
//    · the company admin — any amount;
//    · an office user with "Wallet transfers → Approve" — up to their
//      own approval limit. Above it, only the company admin.
//
//  A transfer can never be larger than the collection money the
//  driver holds on that trip (minus transfers already waiting).
//  Every decision is written to the audit log.
// ══════════════════════════════════════════════════════════════════

final class TransferService
{
    public function __construct(private readonly WalletLedger $ledger) {}

    /** Collection money on this trip the driver can still move. */
    public function available(Trip $trip): float
    {
        if (! $trip->driver_id) {
            return 0.0;
        }

        $held = $this->ledger->balance($trip->driver_id, 'collections', $trip->id);
        $waiting = (float) WalletTransfer::query()->where('trip_id', $trip->id)->where('status', 'pending')->sum('amount');

        return round($held - $waiting, 2);
    }

    /** What the trip's policy decides for this amount: 'auto' or 'pending'. */
    public function decide(Trip $trip, float $amount): string
    {
        return match ($trip->transfer_policy) {
            'auto'  => 'auto',
            'limit' => $amount <= $trip->auto_transfer_limit + 0.001 ? 'auto' : 'pending',
            default => 'pending',
        };
    }

    public function request(Trip $trip, float $amount, string $reason, Actor $actor, ?TripExpense $expense = null): WalletTransfer
    {
        $amount = round($amount, 2);

        if (! $trip->isOpen() || $trip->status === 'planned') {
            throw TripRuleException::because('trips.not_running');
        }
        if (! $trip->driver_id) {
            throw TripRuleException::because('trips.needs_driver');
        }
        if ($amount <= 0) {
            throw TripRuleException::because('trips.amount_positive');
        }
        if ($amount > $this->available($trip) + 0.001) {
            throw TripRuleException::because('trips.transfer_exceeds', ['amount' => number_format($this->available($trip))]);
        }

        $transfer = DB::transaction(function () use ($trip, $amount, $reason, $actor, $expense) {
            $status = $this->decide($trip, $amount);

            $transfer = WalletTransfer::query()->create([
                'company_id'        => $trip->company_id,
                'trip_id'           => $trip->id,
                'driver_id'         => $trip->driver_id,
                'from_wallet'       => 'collections',
                'to_wallet'         => 'custody',
                'amount'            => $amount,
                'reason'            => mb_substr($reason, 0, 250),
                'status'            => $status,
                'policy'            => $trip->transfer_policy,
                'policy_limit'      => $trip->transfer_policy === 'limit' ? $trip->auto_transfer_limit : null,
                'requested_by_type' => $actor->type === 'driver' ? 'driver' : 'user',
                'requested_by_id'   => $actor->id,
                'requested_by_name' => $actor->name,
                'requested_at'      => now(),
                'trip_expense_id'   => $expense?->id,
            ]);

            $this->syncLedger($transfer, $actor);
            Audit::record($status === 'auto' ? 'transfer.auto_approved' : 'transfer.requested', $transfer, [
                'after' => ['trip' => $trip->number, 'amount' => $amount, 'policy' => $trip->transfer_policy, 'status' => $status],
            ]);

            return $transfer;
        });

        // Scope §11: those who may approve it hear about it in their bell (never breaks the request).
        if ($transfer->status === 'pending') {
            $trip->loadMissing('driver:id,name');
            app(Notifier::class)->toOfficeWhere($trip->company_id, 'wallet_transfers.approve', fn (User $u) => $this->canApprove($u, $transfer),
                'transfer.pending', ['trip' => $trip->number, 'amount' => $amount, 'driver' => $trip->driver?->name], route('office.wallets.index', ['tab' => 'pending'], false));
        }

        return $transfer;
    }

    /** Can this office user approve this transfer (permission + approval limit)? */
    public function canApprove(User $user, WalletTransfer $transfer): bool
    {
        if ($user->isCompanyAdmin()) {
            return $user->is_active;
        }

        return $user->can('wallet_transfers.approve')
            && $user->approval_limit !== null
            && $transfer->amount <= (float) $user->approval_limit + 0.001;
    }

    /** Nobody approves or rejects their own request (the company admin may, and it is marked in the audit log). */
    private function assertNotOwnRequest(WalletTransfer $transfer, User $user): bool
    {
        $own = $transfer->requested_by_type === 'user' && (int) $transfer->requested_by_id === $user->id;

        if ($own && ! $user->isCompanyAdmin()) {
            throw TripRuleException::because('trips.own_request');
        }

        return $own;
    }

    public function approve(WalletTransfer $transfer, User $user, ?string $note = null): void
    {
        $this->assertPending($transfer);
        $own = $this->assertNotOwnRequest($transfer, $user);

        if (! $this->canApprove($user, $transfer)) {
            throw TripRuleException::because('trips.above_your_limit', ['limit' => number_format((float) $user->approval_limit)]);
        }

        DB::transaction(function () use ($transfer, $user, $note, $own) {
            $transfer->forceFill(['status' => 'approved', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->syncLedger($transfer, Actor::user($user));
            Audit::record('transfer.approved', $transfer, ['after' => ['amount' => $transfer->amount, 'note' => $note, 'own_request' => $own]]);
        });
    }

    public function reject(WalletTransfer $transfer, User $user, ?string $note = null): void
    {
        $this->assertPending($transfer);
        $this->assertNotOwnRequest($transfer, $user);

        if (! $this->canApprove($user, $transfer)) {
            throw TripRuleException::because('trips.above_your_limit', ['limit' => number_format((float) $user->approval_limit)]);
        }

        DB::transaction(function () use ($transfer, $user, $note) {
            $transfer->forceFill(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->syncLedger($transfer, Actor::user($user));
            Audit::record('transfer.rejected', $transfer, ['after' => ['amount' => $transfer->amount, 'note' => $note, 'expense_withdrawn' => $transfer->trip_expense_id !== null]]);

            // An expense that was waiting on this transfer is not accepted either: leaving it posted
            // would take its amount out of custody with nothing to pay for it. It is withdrawn (audited),
            // the cost and the wallets agree again, and the driver sees the reason.
            if ($transfer->trip_expense_id && ($expense = TripExpense::query()->find($transfer->trip_expense_id))) {
                app(ExpenseService::class)->delete($expense, Actor::user($user));
            }
        });
    }

    /** Mark an automatic transfer as looked at (the "to review" list). */
    public function review(WalletTransfer $transfer, User $user): void
    {
        if ($transfer->status !== 'auto' || $transfer->reviewed_at !== null) {
            throw TripRuleException::because('trips.transfer_not_to_review');
        }

        $transfer->forceFill(['reviewed_by' => $user->id, 'reviewed_at' => now()])->save();
        Audit::record('transfer.reviewed', $transfer, ['after' => ['amount' => $transfer->amount]]);
    }

    /** Withdraw a transfer (its expense was deleted or changed). Money that moved goes back. */
    public function cancel(WalletTransfer $transfer, Actor $actor): void
    {
        if (in_array($transfer->status, ['rejected', 'cancelled'], true)) {
            return;
        }

        $transfer->forceFill(['status' => 'cancelled'])->save();
        $this->syncLedger($transfer, $actor);
        Audit::record('transfer.cancelled', $transfer, ['after' => ['amount' => $transfer->amount]]);
    }

    /** Moved → custody +amount, collections −amount. Otherwise nothing. */
    private function syncLedger(WalletTransfer $transfer, Actor $actor): void
    {
        $targets = $transfer->hasMoved() ? [
            ['custody', 'transfer_in', $transfer->amount],
            ['collections', 'transfer_out', -$transfer->amount],
        ] : [];

        $this->ledger->syncSource($transfer, $transfer->company_id, $transfer->driver_id, $transfer->trip_id, $targets, $actor, $transfer->reason);
    }

    private function assertPending(WalletTransfer $transfer): void
    {
        if ($transfer->status !== 'pending') {
            throw TripRuleException::because('trips.transfer_already_decided');
        }

        if ($transfer->trip && ! $transfer->trip->isOpen()) {
            throw TripRuleException::because('trips.trip_closed');
        }
    }
}
