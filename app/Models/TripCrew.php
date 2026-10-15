<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripCrew (a second driver or an assistant on one trip)
//  Location: app/Models/TripCrew.php
//
//  The main driver holds the money. A crew member is only recorded, with
//  the allowance he gets; the allowance is paid by the main driver and
//  becomes a trip expense (see App\Services\Trips\CrewService).
// ══════════════════════════════════════════════════════════════════

class TripCrew extends Model
{
    use BelongsToCompany;

    protected $table = 'trip_crew';

    public const ROLES = ['second_driver', 'assistant'];

    protected $fillable = [
        'company_id', 'trip_id', 'role', 'name', 'allowance', 'budget_added', 'note', 'trip_expense_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['allowance' => 'float', 'budget_added' => 'float'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(TripExpense::class, 'trip_expense_id');
    }
}
