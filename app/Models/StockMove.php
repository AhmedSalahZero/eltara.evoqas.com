<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — StockMove (a receipt or an issue)
//  Location: app/Models/StockMove.php
//  direction in  = goods received (supplier, unit price)
//  direction out = issued to a truck's maintenance job
// ══════════════════════════════════════════════════════════════════

class StockMove extends Model
{
    use BelongsToCompany;

    public const DIRECTIONS = ['in', 'out'];

    protected $fillable = [
        'company_id', 'store_id', 'stock_item_id', 'direction', 'qty', 'unit_cost', 'amount',
        'supplier', 'maintenance_job_id', 'vehicle_id', 'occurred_at', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'unit_cost' => 'float',
            'amount' => 'float',
            'occurred_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
