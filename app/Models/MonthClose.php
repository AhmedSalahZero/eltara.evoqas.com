<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MonthClose (a closed month and its allocation rate)
//  Location: app/Models/MonthClose.php
//
//  Scope §6.13. One row per month that has been closed. Closing
//  LOCKS the month: its G&A lines and its trip allocations are
//  frozen. Re-opening (status "open") needs the special permission
//  and is recorded in the audit log with the reason.
//    rate = ga_total ÷ km  (EGP per km)
//  revenue / direct_profit / true_profit are the month's figures at
//  the moment of closing (true profit = direct profit − G&A).
//  Written only by App\Services\Closing\MonthCloseService.
// ══════════════════════════════════════════════════════════════════

class MonthClose extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'month', 'status', 'ga_total', 'km', 'rate', 'trips_count', 'revenue', 'direct_profit', 'true_profit',
        'split_rule', 'basis', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason', 'reopen_count',
    ];

    protected function casts(): array
    {
        return [
            'ga_total'      => 'float',
            'km'            => 'float',
            'rate'          => 'float',
            'revenue'       => 'float',
            'direct_profit' => 'float',
            'true_profit'   => 'float',
            'closed_at'     => 'datetime',
            'reopened_at'   => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
