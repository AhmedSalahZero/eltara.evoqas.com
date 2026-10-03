<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SuperAdminSeeder (your platform account)
//  Location: database/seeders/SuperAdminSeeder.php
//
//  Creates the Super Admin from .env: SUPER_ADMIN_EMAIL,
//  SUPER_ADMIN_NAME and DEFAULT_PASSWORD. There is no built-in
//  password on purpose — without DEFAULT_PASSWORD the seeder stops
//  with a clear message rather than inventing one.
//  Safe to run again: an existing account is left as it is (its
//  password is never reset by seeding).
// ══════════════════════════════════════════════════════════════════

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('eltara.default_password');

        if (! $password) {
            throw new RuntimeException('Set DEFAULT_PASSWORD in your .env file, then run the seeder again.');
        }

        User::query()->firstOrCreate(
            ['email' => strtolower(config('eltara.super_admin.email'))],
            [
                'name'              => config('eltara.super_admin.name'),
                'password'          => $password,
                'role'              => UserRole::SuperAdmin->value,
                'language'          => 'ar',
                'is_active'         => true,
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info('Super Admin: '.config('eltara.super_admin.email'));
    }
}
