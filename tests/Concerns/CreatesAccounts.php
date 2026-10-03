<?php

namespace Tests\Concerns;

use App\Models\ClientUser;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\User;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test helpers: ready-made accounts
//  Location: tests/Concerns/CreatesAccounts.php
//  One-line accounts for tests: a company with its admin, an office
//  user with chosen permissions, a driver (PIN 1234), a client user.
//  Every password is "password".
// ══════════════════════════════════════════════════════════════════

trait CreatesAccounts
{
    protected function company(array $attributes = []): Company
    {
        return Company::factory()->create($attributes);
    }

    protected function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    protected function companyAdmin(?Company $company = null): User
    {
        return User::factory()->companyAdmin($company ?? $this->company())->create();
    }

    protected function officeUser(?Company $company = null, array $permissions = [], array $attributes = []): User
    {
        return User::factory()->for($company ?? $this->company())->withPermissions($permissions)->create($attributes);
    }

    protected function driver(?Company $company = null, array $attributes = []): Driver
    {
        return Driver::factory()->for($company ?? $this->company())->create($attributes);
    }

    protected function clientUser(?Company $company = null, array $attributes = []): ClientUser
    {
        $customer = Customer::factory()->for($company ?? $this->company())->create();

        return ClientUser::factory()->for($customer)->create(['company_id' => $customer->company_id, ...$attributes]);
    }
}
