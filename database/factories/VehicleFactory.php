<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

// ══════════════════════════════════════════════════════════════════
//  El Tara — VehicleFactory
//  Location: database/factories/VehicleFactory.php
//      Vehicle::factory()->for($company)->create();
//      Vehicle::factory()->hired()->create();
// ══════════════════════════════════════════════════════════════════

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'company_id'            => Company::factory(),
            'plate_number'          => (string) fake()->unique()->numberBetween(1000, 9999),
            'plate_letters'         => fake()->randomElement(['ن ق ل', 'ن ع ط', 'ن ب ر']),
            'model'                 => 'Mercedes Actros',
            'year'                  => 2020,
            'ownership'             => 'own',
            'status'                => 'available',
            'std_km_per_litre'      => 2.6,
            'licence_expires_at'    => today()->addYear(),
            'insurance_expires_at'  => today()->addYear(),
            'inspection_expires_at' => today()->addYear(),
        ];
    }

    public function hired(): static
    {
        return $this->state(['ownership' => 'hired', 'owner_name' => 'Ragab Hammad']);
    }
}
