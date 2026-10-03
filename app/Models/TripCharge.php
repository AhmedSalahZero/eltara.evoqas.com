<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripCharge (revenue side: extras and deductions)
//  Location: app/Models/TripCharge.php
//  Scope §6.3: extra charges (e.g. waiting / detention) raise the
//  trip's revenue; deductions (e.g. late delivery) lower it.
//  Trip revenue = freight price + extras − deductions (Scope §10).
// ══════════════════════════════════════════════════════════════════

class TripCharge extends Model
{
    use BelongsToCompany;

    public const KINDS = ['extra', 'deduction'];

    protected $fillable = ['company_id', 'trip_id', 'kind', 'label', 'amount', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    /** + for an extra, − for a deduction. */
    public function signedAmount(): float
    {
        return $this->kind === 'deduction' ? -$this->amount : $this->amount;
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
