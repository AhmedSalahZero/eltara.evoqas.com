<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripExpense (one cost line of a trip)
//  Location: app/Models/TripExpense.php
//
//  Scope §6.3, §6.4, §8.2 "Add expense". Paid from (PAID_FROM):
//    custody     → the driver's custody wallet goes down
//    collections → client money was used: a collections → custody
//                  transfer is recorded with it (approval per the
//                  trip's policy), and custody goes down
//    own_pocket  → the driver used his own money: the company owes
//                  him, refunded at settlement
//    company     → paid by the office directly (fuel card, the hired
//                  truck owner's fee …): no wallet moves
//
//  Personal spending (is_personal, no category) is NOT a trip cost:
//  it becomes a driver advance (Scope §6.4).
//  Recorded and changed only through App\Services\Trips\ExpenseService,
//  which also writes the wallet ledger.
// ══════════════════════════════════════════════════════════════════

class TripExpense extends Model
{
    use BelongsToCompany;

    public const PAID_FROM = ['custody', 'collections', 'own_pocket', 'company'];

    protected $fillable = [
        'company_id', 'trip_id', 'driver_id', 'expense_category_id', 'is_personal', 'paid_from', 'amount', 'note',
        'receipt_path', 'spent_at', 'lat', 'lng', 'source', 'wallet_transfer_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'amount'      => 'float',
            'spent_at'    => 'datetime',
            'lat'         => 'float',
            'lng'         => 'float',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(WalletTransfer::class, 'wallet_transfer_id');
    }
}
