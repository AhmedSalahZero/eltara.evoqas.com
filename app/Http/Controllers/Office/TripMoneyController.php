<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\TripExpense;
use App\Models\WalletTransfer;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Services\Trips\ExpenseService;
use App\Services\Trips\SettlementService;
use App\Services\Trips\TransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\TripMoneyController (the trip's money)
//  Location: app/Http/Controllers/Office/TripMoneyController.php
//
//  Scope §6.3 – §6.5, all through the services in app/Services/Trips:
//    expenses    → add / correct / delete a cost line (with receipt)
//                  trip_expenses.create / edit / delete
//    collections → record cash the client handed the driver (as the
//                  driver reported it) · resolve a dispute
//                  wallet_transfers.create / approve
//    transfers   → ask for a collections → custody transfer for the
//                  driver · approve · reject · mark reviewed
//                  wallet_transfers.create / approve (+ approval limit)
//    settle      → close the trip's wallets
//                  trip_settlement.approve
//  A rule refusing an action comes back as a red message (RunsTripRules).
// ══════════════════════════════════════════════════════════════════

class TripMoneyController extends Controller
{
    use RunsTripRules;

    // ── Expenses ───────────────────────────────────────────────────

    public function storeExpense(Request $request, Trip $trip, ExpenseService $service): RedirectResponse
    {
        $data = $this->expenseData($request, $trip);

        return $this->attempt(
            fn () => $service->record($trip, $data, Actor::user($request->user('web')), $request->file('receipt')),
            __('trips.ok.expense'),
        );
    }

    public function updateExpense(Request $request, Trip $trip, TripExpense $expense, ExpenseService $service): RedirectResponse
    {
        $data = $this->expenseData($request, $trip);

        return $this->attempt(
            fn () => $service->update($expense, $data, Actor::user($request->user('web')), $request->file('receipt')),
            __('trips.ok.expense'),
        );
    }

    public function destroyExpense(Request $request, Trip $trip, TripExpense $expense, ExpenseService $service): RedirectResponse
    {
        return $this->attempt(fn () => $service->delete($expense, Actor::user($request->user('web'))), __('trips.ok.expense_deleted'));
    }

    // ── Cash from the client ───────────────────────────────────────

    public function storeCollection(Request $request, Trip $trip, CollectionService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount'      => ['required', 'numeric', 'min:1', 'max:10000000'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note'        => ['nullable', 'string', 'max:250'],
            'receipt'     => ['nullable', 'image', 'max:8192'],
        ]);

        return $this->attempt(fn () => $service->record(
            $trip, (float) $data['amount'], 'office', Actor::user($request->user('web')), $data['note'] ?? null, $data['received_at'] ?? null, $request->file('receipt'),
        ), __('trips.ok.collection'));
    }

    public function resolveCollection(Request $request, Trip $trip, TripCollection $collection, CollectionService $service): RedirectResponse
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['accepted', 'cancelled'])],
            'note'       => ['nullable', 'string', 'max:200'],
        ]);

        return $this->attempt(fn () => $service->resolve($collection, $data['resolution'], $request->user('web'), $data['note'] ?? null), __('trips.ok.resolved'));
    }

    // ── Transfers ──────────────────────────────────────────────────

    public function storeTransfer(Request $request, Trip $trip, TransferService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        return $this->attempt(function () use ($service, $trip, $data, $request) {
            $transfer = $service->request($trip, (float) $data['amount'], $data['reason'], Actor::user($request->user('web')));

            return back()->with('success', __($transfer->status === 'auto' ? 'trips.ok.transfer_auto' : 'trips.ok.transfer_pending'));
        });
    }

    public function approveTransfer(Request $request, WalletTransfer $transfer, TransferService $service): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:250']])['note'] ?? null;

        return $this->attempt(fn () => $service->approve($transfer, $request->user('web'), $note), __('trips.ok.approved'));
    }

    public function rejectTransfer(Request $request, WalletTransfer $transfer, TransferService $service): RedirectResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:250']])['note'];

        return $this->attempt(fn () => $service->reject($transfer, $request->user('web'), $note), __('trips.ok.rejected'));
    }

    public function reviewTransfer(Request $request, WalletTransfer $transfer, TransferService $service): RedirectResponse
    {
        return $this->attempt(fn () => $service->review($transfer, $request->user('web')), __('trips.ok.reviewed'));
    }

    // ── Settlement ─────────────────────────────────────────────────

    public function settle(Request $request, Trip $trip, SettlementService $service): RedirectResponse
    {
        $data = $request->validate([
            'note'             => ['nullable', 'string', 'max:250'],
            'received_by'      => ['nullable', 'string', 'max:120'],
            'accept_unconfirmed' => ['nullable', 'boolean'],
        ]);
        $note = $data['note'] ?? null;

        return $this->attempt(function () use ($service, $trip, $request, $note, $data) {
            $s = $service->settle($trip, $request->user('web'), $note, $data['received_by'] ?? null, (bool) ($data['accept_unconfirmed'] ?? false));

            return back()->with('success', __('trips.ok.settled', ['number' => $trip->number, 'net' => number_format(abs($s->net_amount))]));
        });
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function expenseData(Request $request, Trip $trip): array
    {
        return $request->validate([
            'is_personal'         => ['boolean'],
            'expense_category_id' => ['nullable', 'required_unless:is_personal,true,1', 'integer', Rule::exists('expense_categories', 'id')->where('company_id', $trip->company_id)],
            'paid_from'           => ['required', Rule::in(TripExpense::PAID_FROM)],
            'amount'              => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'note'                => ['nullable', 'string', 'max:250'],
            'spent_at'            => ['nullable', 'date', 'before_or_equal:now'],
            'receipt'             => ['nullable', 'image', 'max:8192'],
        ]);
    }
}
