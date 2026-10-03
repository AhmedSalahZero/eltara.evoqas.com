<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CustomerFactory
//  Location: database/factories/CustomerFactory.php
// ══════════════════════════════════════════════════════════════════

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'company_id'          => Company::factory(),
            'name_ar'             => 'عميل '.fake()->unique()->lastName(),
            'name_en'             => fake()->unique()->company(),
            'payment_terms_days'  => 30,
            'may_pay_driver_cash' => true,
            'is_active'           => true,
        ];
    }
}
