<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  El Tara — StockItem (oil, a filter, a tyre …)
//  Location: app/Models/StockItem.php
//  Quantity and value live on StockBalance, one row per store.
// ══════════════════════════════════════════════════════════════════

class StockItem extends Model
{
    use BelongsToCompany;

    public const UNITS = ['litre', 'piece', 'kg'];

    protected $fillable = ['company_id', 'name', 'unit'];

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
