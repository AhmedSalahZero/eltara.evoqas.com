<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DriverFactory
//  Location: database/factories/DriverFactory.php
//      Driver::factory()->for($company)->create();   // PIN is 1234
// ══════════════════════════════════════════════════════════════════

/** @extends Factory<Driver> */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    public function definition(): array
    {
        return [
            'company_id'         => Company::factory(),
            'name'               => fake()->name('male'),
            'mobile'             => '010'.fake()->unique()->numerify('########'),
            'pin'                => '1234',
            'license_number'     => fake()->numerify('DL-######'),
            'license_expires_at' => today()->addYears(2),
            'pay_basis'          => 'fixed_plus_trip',
            'joined_at'          => today()->subYear(),
            'language'           => 'ar',
            'is_active'          => true,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['is_active' => false]);
    }
}
