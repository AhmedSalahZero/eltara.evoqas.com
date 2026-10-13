<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MaintenanceJobLine (one item issued from the workshop)
//  Location: app/Models/MaintenanceJobLine.php
//  unit_cost and amount are filled when the line is issued.
// ══════════════════════════════════════════════════════════════════

class MaintenanceJobLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'maintenance_job_id', 'stock_item_id', 'qty', 'unit_cost', 'amount', 'stock_move_id',
    ];

    protected function casts(): array
    {
        return ['qty' => 'float', 'unit_cost' => 'float', 'amount' => 'float'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJob::class, 'maintenance_job_id');
    }
}
