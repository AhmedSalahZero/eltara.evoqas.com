<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DriverAdvance (a personal advance / سلفة)
//  Location: app/Models/DriverAdvance.php
//
//  Scope §6.12. A personal loan to the driver, deducted from salary.
//  Step 3 creates advances automatically from PERSONAL spending out
//  of custody on a trip (source "trip_personal" — marked on screens).
//  Step 6 adds the advances screen: manual advances, the monthly
//  instalment, repayments and the payroll deduction total
//  (App\Services\Advances\AdvanceService).
//  The advances WALLET balance is the ledger (wallet = advances).
// ══════════════════════════════════════════════════════════════════

class DriverAdvance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'driver_id', 'trip_id', 'trip_expense_id', 'amount', 'reason', 'monthly_instalment',
        'repaid_amount', 'source', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'monthly_instalment' => 'float', 'repaid_amount' => 'float'];
    }

    public function remaining(): float
    {
        return round($this->amount - $this->repaid_amount, 2);
    }

    public function repayments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DriverAdvanceRepayment::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
