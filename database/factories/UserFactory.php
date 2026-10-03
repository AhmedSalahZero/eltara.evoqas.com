<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  El Tara — UserFactory
//  Location: database/factories/UserFactory.php
//      User::factory()->superAdmin()->create();
//      User::factory()->companyAdmin($company)->create();
//      User::factory()->for($company)->withPermissions(['trips.view'])->create();
//  Default: an activated office user of a new company, password "password".
// ══════════════════════════════════════════════════════════════════

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'company_id'        => Company::factory(),
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'password'          => static::$password ??= 'password',
            'role'              => UserRole::OfficeUser->value,
            'permissions'       => [],
            'language'          => 'ar',
            'is_active'         => true,
            'email_verified_at' => now(),
            'remember_token'    => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['company_id' => null, 'role' => UserRole::SuperAdmin->value]);
    }

    public function companyAdmin(?Company $company = null): static
    {
        return $this->state(fn () => array_filter([
            'role'       => UserRole::CompanyAdmin->value,
            'company_id' => $company?->id,
        ]));
    }

    public function withPermissions(array $keys, ?float $approvalLimit = null): static
    {
        return $this->state(['permissions' => $keys, 'approval_limit' => $approvalLimit]);
    }

    public function notActivated(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(['is_active' => false]);
    }
}
