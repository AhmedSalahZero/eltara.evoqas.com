<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripRouteBudget (one standard cost line of a route)
//  Location: app/Models/TripRouteBudget.php
//  e.g. route "Obour → Alexandria Port", category Fuel, 3,450 EGP.
// ══════════════════════════════════════════════════════════════════

class TripRouteBudget extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'trip_route_id', 'expense_category_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
