<?php

namespace App\Sync\Handlers;

use App\Models\Driver;
use App\Models\WalletTransfer;
use App\Services\Trips\Actor;
use App\Services\Trips\ExpenseService;
use App\Services\Trips\TripRuleException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ExpenseHandler (Driver App entry "trip.expense")
//  Location: app/Sync/Handlers/ExpenseHandler.php
//
//  "Add expense" (Scope §8.2): category, amount, which wallet paid
//  (custody / collections / own pocket), note and the receipt photo.
//  The time is when the driver spent it, not when the phone got signal.
//  Paid from collections makes a transfer request under the trip's
//  policy, exactly as in the office. A refused entry tells the driver
//  why; nothing is half-saved (the sync runs it in one transaction).
// ══════════════════════════════════════════════════════════════════

final class ExpenseHandler extends TripEntryHandler
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function rules(): array
    {
        return [
            'trip_id'             => ['required', 'integer'],
            'expense_category_id' => ['nullable', 'integer'],
            'is_personal'         => ['sometimes', 'boolean'],
            // Drivers never pick "company" — that is for the office.
            'paid_from'           => ['required', 'in:custody,collections,own_pocket'],
            'amount'              => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'note'                => ['nullable', 'string', 'max:250'],
            'photo'               => ['nullable', 'uuid'],
            ...self::LOCATION_RULES,
        ];
    }

    protected function run(Driver $driver, Actor $actor, array $payload, ?\DateTimeInterface $at): array
    {
        $trip = $this->trip($driver, (int) $payload['trip_id']);
        $receipt = $this->photo($driver, $payload['photo'] ?? null);
        $personal = (bool) ($payload['is_personal'] ?? false);

        if (! $receipt && ! $personal && $this->photoRequired($driver)) {
            throw TripRuleException::because('trips.receipt_required');
        }

        [$lat, $lng] = $this->location($driver, $payload);

        $expense = $this->expenses->record($trip, [
            'expense_category_id' => $payload['expense_category_id'] ?? null,
            'is_personal'         => $personal,
            'paid_from'           => $payload['paid_from'],
            'amount'              => $payload['amount'],
            'note'                => $payload['note'] ?? null,
            'spent_at'            => $at ?? now(),
            'lat'                 => $lat,
            'lng'                 => $lng,
        ], $actor, $receipt);

        return ['expense_id' => $expense->id, 'transfer' => $expense->wallet_transfer_id ? WalletTransfer::query()->whereKey($expense->wallet_transfer_id)->value('status') : null];
    }
}
