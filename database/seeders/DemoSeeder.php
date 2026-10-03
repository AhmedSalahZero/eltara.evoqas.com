<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  El Tara — DemoSeeder (sample data for trying the app)
//  Location: database/seeders/DemoSeeder.php
//
//  The demo's company, so you can sign in as every kind of account:
//
//    Company admin  ahmed@nile-transport.test     password: DEFAULT_PASSWORD
//    Fleet manager  mona@nile-transport.test      password: DEFAULT_PASSWORD
//    CFO            karim@nile-transport.test     password: DEFAULT_PASSWORD
//    Client user    ashraf@sinai-marble.test      password: DEFAULT_PASSWORD
//    Driver         mobile 01001234567            PIN: 1234
//
//  Then MasterDataSeeder adds the Step 2 data (routes, customers,
//  rate cards, drivers, vehicles), and TripSeeder the Step 3 demo
//  trips (settled, on the road, waiting for approval and settlement).
//  Runs only on your computer (DatabaseSeeder). Safe to run again.
// ══════════════════════════════════════════════════════════════════

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('eltara.default_password');

        $company = Company::query()->firstOrCreate(['name_en' => 'Nile Heavy Transport'], [
            'name_ar'                => 'النيل للنقل الثقيل',
            'status'                 => CompanyStatus::Active,
            'office_users_limit'     => 8,
            'driver_accounts_limit'  => 20,
            'subscription_starts_at' => today()->startOfYear(),
            'subscription_ends_at'   => today()->addMonths(9),
            'default_language'       => 'ar',
            'default_theme'          => 'dark',
            'contact_phone'          => '0223456789',
        ]);

        $people = [
            ['ahmed@nile-transport.test', 'أحمد الشريف', UserRole::CompanyAdmin, 'مدير الشركة', [], null],
            ['mona@nile-transport.test', 'منى عبد الحميد', UserRole::OfficeUser, 'مدير الأسطول',
                ['dashboard.view', 'trips.view', 'trips.create', 'trips.edit', 'vehicles.view', 'vehicles.edit', 'drivers.view', 'drivers.edit', 'wallet_transfers.view', 'wallet_transfers.approve'], 1000],
            ['karim@nile-transport.test', 'كريم فوزي', UserRole::OfficeUser, 'المدير المالي',
                ['dashboard.view', 'trips.view', 'trip_settlement.view', 'trip_settlement.approve', 'month_close.view', 'month_close.create', 'month_close.approve', 'reports.view'], null],
        ];

        foreach ($people as [$email, $name, $role, $title, $permissions, $limit]) {
            User::query()->firstOrCreate(['email' => $email], [
                'company_id'        => $company->id,
                'name'              => $name,
                'password'          => $password,
                'role'              => $role->value,
                'job_title'         => $title,
                'permissions'       => $permissions,
                'approval_limit'    => $limit,
                'language'          => 'ar',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);
        }

        Driver::query()->withoutGlobalScopes()->firstOrCreate(['mobile' => '01001234567'], [
            'company_id'         => $company->id,
            'name'               => 'محمود السيد',
            'pin'                => '1234',
            'license_number'     => 'DL-204518',
            'license_expires_at' => today()->addMonths(20),
            'pay_basis'          => 'fixed_plus_trip',
            'joined_at'          => today()->subYears(3),
            'is_active'          => true,
        ]);

        $customer = Customer::query()->withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id, 'name_en' => 'Sinai Marble'], [
            'name_ar'             => 'سيناء للرخام',
            'payment_terms_days'  => 45,
            'may_pay_driver_cash' => true,
            'is_active'           => true,
        ]);

        ClientUser::query()->withoutGlobalScopes()->firstOrCreate(['email' => 'ashraf@sinai-marble.test'], [
            'company_id'        => $company->id,
            'customer_id'       => $customer->id,
            'name'              => 'م. أشرف البنا',
            'job_title'         => 'مدير المشتريات',
            'password'          => $password,
            'is_account_admin'  => true,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        $this->call(MasterDataSeeder::class);
        $this->call(TripSeeder::class);
        $this->call(Step6Seeder::class);

        $this->command?->info('Demo company "Nile Heavy Transport" is ready (see docs/SETUP.md for the sign-ins).');
    }
}
