<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CompanyFactory
//  Location: database/factories/CompanyFactory.php
//  Makes sample companies for tests and demo data:
//      Company::factory()->create();
//      Company::factory()->suspended()->create();
//      Company::factory()->expired()->create();   // read-only
// ══════════════════════════════════════════════════════════════════

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name_ar'                => 'شركة '.fake()->unique()->lastName().' للنقل',
            'name_en'                => fake()->unique()->company().' Transport',
            'status'                 => CompanyStatus::Active,
            'office_users_limit'     => 5,
            'driver_accounts_limit'  => 15,
            'subscription_starts_at' => today()->subMonth(),
            'subscription_ends_at'   => today()->addYear(),
            'default_language'       => 'ar',
            'default_theme'          => 'dark',
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => CompanyStatus::Suspended]);
    }

    public function expired(): static
    {
        return $this->state(['subscription_ends_at' => today()->subDay()]);
    }
}
