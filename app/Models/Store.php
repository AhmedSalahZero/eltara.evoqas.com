<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Store (a company workshop's warehouse)
//  Location: app/Models/Store.php
// ══════════════════════════════════════════════════════════════════

class Store extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
