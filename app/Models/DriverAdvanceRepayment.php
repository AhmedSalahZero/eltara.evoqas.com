<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DriverAdvanceRepayment (money taken back from an advance)
//  Location: app/Models/DriverAdvanceRepayment.php
//
//  Scope §6.12. method "payroll" = deducted from the driver's salary
//  for deduction_month (first day of the month); "cash" = he paid it
//  back by hand. Each one also posts a row to the driver's advances
//  wallet (WalletLedger), so the wallet always equals what is owed.
//  Written only by App\Services\Advances\AdvanceService.
// ══════════════════════════════════════════════════════════════════

class DriverAdvanceRepayment extends Model
{
    use BelongsToCompany;

    public const METHODS = ['payroll', 'cash'];

    protected $fillable = ['company_id', 'driver_advance_id', 'driver_id', 'amount', 'method', 'deduction_month', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(DriverAdvance::class, 'driver_advance_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
