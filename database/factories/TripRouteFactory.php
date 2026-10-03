<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\TripRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripRouteFactory
//  Location: database/factories/TripRouteFactory.php
//      TripRoute::factory()->for($company)->create();   // 450 km round trip
// ══════════════════════════════════════════════════════════════════

/** @extends Factory<TripRoute> */
class TripRouteFactory extends Factory
{
    protected $model = TripRoute::class;

    public function definition(): array
    {
        return [
            'company_id'     => Company::factory(),
            'origin_ar'      => 'العبور',
            'origin_en'      => 'Obour',
            'destination_ar' => 'ميناء الإسكندرية',
            'destination_en' => 'Alexandria Port',
            'weight_tons'    => 25,
            'km_round_trip'  => 450,
            'usual_hours'    => 17,
            'is_active'      => true,
        ];
    }
}
