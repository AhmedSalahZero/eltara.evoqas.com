<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripRoute (a route: origin → destination, round trip)
//  Location: app/Models/TripRoute.php
//
//  Scope §6.6. weight_tons (الحمولة) is part of the route and of its
//  name: "6 October ← Alexandria – 5 Ton". Its standard budget per expense category lives in
//  budgets(). The helpers below give the figures the screens show:
//    standardTotal()    — all standard costs of one round trip
//    cashRoadCosts()    — the part the driver pays in cash
//    suggestedCustody() — cash road costs × (1 + buffer %), rounded
//                         UP to the next 500 (Scope §10)
// ══════════════════════════════════════════════════════════════════

class TripRoute extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'origin_ar', 'origin_en', 'destination_ar', 'destination_en',
        'weight_tons', 'km_round_trip', 'usual_hours', 'notes', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['km_round_trip' => 'integer', 'usual_hours' => 'float', 'weight_tons' => 'float', 'is_active' => 'boolean'];
    }

    public function displayName(?string $locale = null): string
    {
        $en = ($locale ?? app()->getLocale()) === 'en';
        $from = $en && $this->origin_en ? $this->origin_en : $this->origin_ar;
        $to = $en && $this->destination_en ? $this->destination_en : $this->destination_ar;

        return $from.($en ? ' → ' : ' ← ').$to.$this->weightLabel($en);
    }

    /** " – 5 Ton" / " – 5 طن" (nothing for an old route with no weight yet). */
    public function weightLabel(bool $english): string
    {
        if ($this->weight_tons === null) {
            return '';
        }
        $n = rtrim(rtrim(number_format($this->weight_tons, 2, '.', ''), '0'), '.');

        return ' – '.$n.($english ? ' Ton' : ' طن');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(TripRouteBudget::class);
    }

    public function rateCards(): HasMany
    {
        return $this->hasMany(RateCard::class);
    }

    public function standardTotal(): float
    {
        return (float) $this->budgets->sum('amount');
    }

    public function cashRoadCosts(): float
    {
        return (float) $this->budgets->sum(fn (TripRouteBudget $b) => $b->amount * ($b->category?->cash_percent ?? 100) / 100);
    }

    public function suggestedCustody(float $bufferPercent): float
    {
        $cash = $this->cashRoadCosts();

        return $cash > 0 ? ceil($cash * (1 + $bufferPercent / 100) / 500) * 500 : 0.0;
    }
}
