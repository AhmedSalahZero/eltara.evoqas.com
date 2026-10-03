<?php

namespace Database\Factories;

use App\Models\ClientUser;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ClientUserFactory
//  Location: database/factories/ClientUserFactory.php
//      ClientUser::factory()->for($customer)->create();  // password "password"
//  company_id always follows the customer's company.
// ══════════════════════════════════════════════════════════════════

/** @extends Factory<ClientUser> */
class ClientUserFactory extends Factory
{
    protected $model = ClientUser::class;

    public function definition(): array
    {
        return [
            'customer_id'       => Customer::factory(),
            'company_id'        => fn (array $a) => Customer::query()->withoutGlobalScopes()->find($a['customer_id'])->company_id,
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'password'          => 'password',
            'is_account_admin'  => false,
            'language'          => 'ar',
            'is_active'         => true,
            'email_verified_at' => now(),
        ];
    }
}
