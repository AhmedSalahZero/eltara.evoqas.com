<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — StockBalance (quantity and value of one item in one store)
//  Location: app/Models/StockBalance.php
//  Average cost = value ÷ qty. Receipts raise both; issues take the
//  average and lower both.
// ══════════════════════════════════════════════════════════════════

class StockBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'store_id', 'stock_item_id', 'qty', 'value'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'value' => 'float'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function average(): float
    {
        return $this->qty > 0 ? $this->value / $this->qty : 0.0;
    }
}
