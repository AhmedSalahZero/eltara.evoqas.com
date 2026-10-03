<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — WalletTransfer (money moved between a driver's wallets)
//  Location: app/Models/WalletTransfer.php
//
//  Scope §6.4: "Money never moves between wallets silently." Every
//  movement is a transfer with an amount, a reason, a time and its
//  approval status (STATUSES):
//    pending   waiting for a manager (the money has NOT moved yet)
//    approved  a manager approved it (who and when are kept)
//    auto      went through automatically under the trip's policy —
//              still listed "to review" until someone marks it
//              reviewed (reviewed_at)
//    rejected  refused — the money did not move
//    cancelled withdrawn (e.g. the expense it paid for was deleted)
//
//  Today the only direction is collections → custody (the driver uses
//  client money for road costs). The columns allow others later.
//  Decided only through App\Services\Trips\TransferService.
// ══════════════════════════════════════════════════════════════════

class WalletTransfer extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['pending', 'approved', 'auto', 'rejected', 'cancelled'];

    /** Statuses in which the money has actually moved. */
    public const MOVED = ['approved', 'auto'];

    protected $fillable = [
        'company_id', 'trip_id', 'driver_id', 'from_wallet', 'to_wallet', 'amount', 'reason', 'status', 'policy', 'policy_limit',
        'requested_by_type', 'requested_by_id', 'requested_by_name', 'requested_at',
        'decided_by', 'decided_at', 'decision_note', 'reviewed_by', 'reviewed_at', 'trip_expense_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'float',
            'policy_limit' => 'float',
            'requested_at' => 'datetime',
            'decided_at'   => 'datetime',
            'reviewed_at'  => 'datetime',
        ];
    }

    public function hasMoved(): bool
    {
        return in_array($this->status, self::MOVED, true);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(TripExpense::class, 'trip_expense_id');
    }
}
