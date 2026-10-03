<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DatabaseSeeder
//  Location: database/seeders/DatabaseSeeder.php
//
//  `php artisan db:seed` runs:
//    SuperAdminSeeder  → your platform account (always)
//    DemoSeeder        → "Nile Heavy Transport" sample company with
//                        an admin, two office users, a driver, a
//                        client and a client-portal user — only on
//                        your computer (APP_ENV=local), never on the
//                        live server.
// ══════════════════════════════════════════════════════════════════

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
