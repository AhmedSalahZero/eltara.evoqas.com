<?php

namespace App\Services\Trips;

use App\Models\CompanySetting;
use App\Models\DriverAdvance;
use App\Models\ExpenseCategory;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\WalletTransfer;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ExpenseService (trip cost lines and their wallet effects)
//  Location: app/Services/Trips/ExpenseService.php
//
//  Scope §6.3, §6.4, §8.2 "Add expense". What each expense does to
//  the driver's wallets (the ledger):
//
//    paid from custody      custody − amount
//    paid from collections  a collections → custody transfer is
//                           recorded (approved, automatic or waiting,
//                           per the trip's policy) and custody − amount
//    paid from own pocket   pocket + amount (refunded at settlement)
//    paid by the company    nothing (fuel card, hired-truck fee …)
//
//    PERSONAL spending (no category) is not a trip cost. With the
//    company setting "personal spend → advance" ON (the default) it
//    leaves custody and becomes a driver advance: custody − amount,
//    advances + amount, and an advance record marked "from trip".
//    With the setting OFF it stays in custody, so the driver hands it
//    back at settlement instead. It cannot be paid from own pocket.
//
//  CUSTODY MAY GO BELOW ZERO — on purpose. A driver who really paid more
//  than he was handed is owed the difference (the settlement shows it as
//  "the company pays the driver"). The phone only warns him; the server
//  never refuses a real expense. But it is never silent: the audit entry
//  of an expense that takes custody below zero says `overdrawn`, with the
//  balance left, so management can review it.
//
//  Correcting or deleting an expense adds correcting ledger rows,
//  cancels the transfer / advance it created, and is audited.
//  Nothing can change once the trip is settled or cancelled.
//
//  Receipt photos go to the private "trip_files" disk under
//  trips/{trip id}/receipts/.
// ══════════════════════════════════════════════════════════════════

final class ExpenseService
{
    public function __construct(
        private readonly WalletLedger $ledger,
        private readonly TransferService $transfers,
    ) {}

    /**
     * @param  array{expense_category_id?:?int, is_personal?:bool, paid_from:string, amount:float|string,
     *               note?:?string, spent_at?:mixed, lat?:?float, lng?:?float, source?:string}  $data
     */
    public function record(Trip $trip, array $data, Actor $actor, UploadedFile|string|null $receipt = null): TripExpense
    {
        $data = $this->normalise($trip, $data);
        $this->assertAllowed($trip, $data);

        return DB::transaction(function () use ($trip, $data, $actor, $receipt) {
            $expense = TripExpense::query()->create($data + [
                'company_id' => $trip->company_id,
                'trip_id'    => $trip->id,
                'driver_id'  => $trip->driver_id,
                'source'     => $actor->isDriver() ? 'driver' : 'office',
                'created_by' => $actor->userId(),
            ]);

            if ($receipt) {
                $expense->forceFill(['receipt_path' => $this->storeReceipt($trip, $receipt)])->save();
            }

            $this->apply($trip, $expense, $actor);
            Audit::record('expense.created', $expense, ['after' => $this->auditView($trip, $expense) + $this->overdraft($trip, $expense)]);

            return $expense;
        });
    }

    public function update(TripExpense $expense, array $data, Actor $actor, UploadedFile|string|null $receipt = null): TripExpense
    {
        $trip = $expense->trip;
        $data = $this->normalise($trip, $data);
        $this->assertAllowed($trip, $data, $expense);

        return DB::transaction(function () use ($trip, $expense, $data, $actor, $receipt) {
            $before = $this->auditView($trip, $expense);
            $moneyChanged = round((float) $data['amount'], 2) !== round($expense->amount, 2)
                || $data['paid_from'] !== $expense->paid_from
                || (bool) $data['is_personal'] !== $expense->is_personal;

            $expense->fill($data);
            if ($receipt) {
                $expense->receipt_path = $this->storeReceipt($trip, $receipt);
            }
            $expense->save();

            // Only money changes touch the wallets; renaming the category or
            // fixing the note does not re-ask for a transfer approval.
            if ($moneyChanged) {
                $this->apply($trip, $expense, $actor);
            }

            Audit::record('expense.updated', $expense, ['before' => $before, 'after' => $this->auditView($trip, $expense) + $this->overdraft($trip, $expense)]);

            return $expense;
        });
    }

    public function delete(TripExpense $expense, Actor $actor): void
    {
        $trip = $expense->trip;
        $this->assertTripOpen($trip);

        DB::transaction(function () use ($trip, $expense, $actor) {
            Audit::record('expense.deleted', $expense, ['before' => $this->auditView($trip, $expense)]);

            $this->cancelLinks($expense, $actor);
            $this->ledger->syncSource($expense, $trip->company_id, $expense->driver_id, $trip->id, [], $actor, __('trips.ledger_expense_deleted'));
            $expense->delete();
        });
    }

    // ── Wallet effects ─────────────────────────────────────────────

    /** Makes the ledger, the transfer and the advance match the expense as it is now. */
    private function apply(Trip $trip, TripExpense $expense, Actor $actor): void
    {
        $this->cancelLinks($expense, $actor);

        $amount = $expense->amount;
        $toAdvance = $expense->is_personal && CompanySetting::for($trip->company_id)->personal_spend_to_advance;
        $targets = [];

        if ($expense->paid_from === 'collections') {
            $transfer = $this->transfers->request($trip, $amount, $this->transferReason($expense), $actor, $expense);
            $expense->forceFill(['wallet_transfer_id' => $transfer->id])->save();
        }

        if (in_array($expense->paid_from, ['custody', 'collections'], true)) {
            if (! $expense->is_personal) {
                $targets[] = ['custody', 'expense', -$amount];
            } elseif ($toAdvance) {
                $targets[] = ['custody', 'personal_to_advance', -$amount];
                $targets[] = ['advances', 'advance_created', $amount];

                DriverAdvance::query()->create([
                    'company_id'      => $trip->company_id,
                    'driver_id'       => $expense->driver_id,
                    'trip_id'         => $trip->id,
                    'trip_expense_id' => $expense->id,
                    'amount'          => $amount,
                    'reason'          => $expense->note ?: __('trips.personal_on_trip', ['trip' => $trip->number]),
                    'source'          => 'trip_personal',
                    'status'          => 'open',
                    'created_by'      => $actor->userId(),
                ]);
            }
            // Personal with the setting off: it stays in custody and is
            // handed back at settlement — nothing to post.
        }

        if ($expense->paid_from === 'own_pocket') {
            $targets[] = ['pocket', 'own_pocket', $amount];
        }

        $this->ledger->syncSource($expense, $trip->company_id, $expense->driver_id, $trip->id, $targets, $actor, $expense->note);
    }

    /** Cancels the transfer and the advance an earlier version of this expense created. */
    private function cancelLinks(TripExpense $expense, Actor $actor): void
    {
        if ($expense->wallet_transfer_id) {
            $transfer = WalletTransfer::query()->find($expense->wallet_transfer_id);
            if ($transfer) {
                $this->transfers->cancel($transfer, $actor);
            }
            $expense->forceFill(['wallet_transfer_id' => null])->save();
        }

        DriverAdvance::query()->where('trip_expense_id', $expense->id)->where('status', 'open')
            ->update(['status' => 'cancelled']);
    }

    // ── Rules ──────────────────────────────────────────────────────

    private function normalise(Trip $trip, array $data): array
    {
        $personal = (bool) ($data['is_personal'] ?? false);

        return [
            'expense_category_id' => $personal ? null : ($data['expense_category_id'] ?? null),
            'is_personal'         => $personal,
            'paid_from'           => (string) $data['paid_from'],
            'amount'              => round((float) $data['amount'], 2),
            'note'                => isset($data['note']) && $data['note'] !== '' ? mb_substr((string) $data['note'], 0, 250) : null,
            'spent_at'            => $data['spent_at'] ?? now(),
            'lat'                 => $data['lat'] ?? null,
            'lng'                 => $data['lng'] ?? null,
        ];
    }

    private function assertAllowed(Trip $trip, array $data, ?TripExpense $existing = null): void
    {
        $this->assertTripOpen($trip);

        if ($data['amount'] <= 0) {
            throw TripRuleException::because('trips.amount_positive');
        }
        if (! in_array($data['paid_from'], TripExpense::PAID_FROM, true)) {
            throw TripRuleException::because('trips.bad_paid_from');
        }
        if (! $data['is_personal'] && ! ExpenseCategory::query()->whereKey($data['expense_category_id'])->exists()) {
            throw TripRuleException::because('trips.category_required');
        }
        if ($data['is_personal'] && ! in_array($data['paid_from'], ['custody', 'collections'], true)) {
            throw TripRuleException::because('trips.personal_from_wallet');
        }

        // Anything touching the driver's wallets needs a driver on the trip,
        // and the trip must have started (the driver accepted it).
        if ($data['paid_from'] !== 'company') {
            if (! $trip->driver_id) {
                throw TripRuleException::because('trips.needs_driver');
            }
            if ($trip->status === 'planned') {
                throw TripRuleException::because('trips.not_running');
            }
        }

        // A hired truck gets no custody (Scope §6.7).
        if ($trip->is_hired && $data['paid_from'] === 'custody') {
            throw TripRuleException::because('trips.hired_no_custody');
        }

        if ($data['paid_from'] === 'collections') {
            // When correcting an expense already paid from collections, its
            // own transfer is about to be cancelled, so its amount is free again.
            $own = $existing?->paid_from === 'collections' && $existing->wallet_transfer_id
                ? (float) WalletTransfer::query()->whereKey($existing->wallet_transfer_id)->whereIn('status', ['pending', 'approved', 'auto'])->value('amount')
                : 0.0;

            if ($data['amount'] > $this->transfers->available($trip) + $own + 0.001) {
                throw TripRuleException::because('trips.transfer_exceeds', ['amount' => number_format($this->transfers->available($trip) + $own)]);
            }
        }
    }

    private function assertTripOpen(Trip $trip): void
    {
        if (! $trip->isOpen()) {
            throw TripRuleException::because('trips.trip_closed');
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function storeReceipt(Trip $trip, UploadedFile|string $file): string
    {
        // A string is a photo the Driver App already uploaded (DriverUpload).
        return is_string($file) ? $file : $file->store("trips/{$trip->id}/receipts", 'trip_files');
    }

    private function transferReason(TripExpense $expense): string
    {
        $what = $expense->is_personal ? __('trips.personal') : ($expense->category?->displayName() ?? '');

        return trim(__('trips.transfer_for_expense', ['what' => $what]).($expense->note ? ' — '.$expense->note : ''));
    }

    /** Is the driver's custody for this trip below zero after this expense? */
    private function overdraft(Trip $trip, TripExpense $expense): array
    {
        if (! $expense->driver_id) {
            return ['overdrawn' => false];
        }

        $left = $this->ledger->balance($expense->driver_id, 'custody', $trip->id);

        return $left < -0.001 ? ['overdrawn' => true, 'custody_left' => $left] : ['overdrawn' => false];
    }

    private function auditView(Trip $trip, TripExpense $e): array
    {
        return [
            'trip'      => $trip->number,
            'category'  => $e->is_personal ? 'personal' : $e->expense_category_id,
            'paid_from' => $e->paid_from,
            'amount'    => $e->amount,
            'note'      => $e->note,
        ];
    }
}
