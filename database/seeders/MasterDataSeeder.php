<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\ExpenseCategory;
use App\Models\RateCard;
use App\Models\TripRoute;
use App\Models\TripRouteBudget;
use App\Models\Vehicle;
use App\Services\CompanyDefaults;
use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MasterDataSeeder (Step 2 demo data)
//  Location: database/seeders/MasterDataSeeder.php
//
//  Fills "Nile Heavy Transport" with the demo's master data so every
//  Step 2 screen has something to show:
//    10 routes with standard budgets (the demo's figures),
//    8 customers with their rate cards,
//    7 more drivers (8 with Mahmoud), 10 own trucks + 2 hired,
//    a few documents expiring soon (to see the alerts),
//    G&A estimate 4.50 EGP/km (to see "profit after G&A").
//  Safe to run again: existing records are left as they are.
//  Runs only on your computer (DatabaseSeeder → DemoSeeder).
// ══════════════════════════════════════════════════════════════════

class MasterDataSeeder extends Seeder
{
    private const CITIES = [
        'obour' => ['العبور', 'Obour'], 'alex' => ['ميناء الإسكندرية', 'Alexandria Port'], 'tenth' => ['العاشر من رمضان', '10th of Ramadan'],
        'sokhna' => ['العين السخنة', 'Ain Sokhna'], 'sadat' => ['مدينة السادات', 'Sadat City'], 'damietta' => ['ميناء دمياط', 'Damietta Port'],
        'cairo' => ['القاهرة', 'Cairo'], 'assiut' => ['أسيوط', 'Assiut'], 'october' => ['6 أكتوبر', '6th of October'],
        'portsaid' => ['بورسعيد', 'Port Said'], 'hurghada' => ['الغردقة', 'Hurghada'], 'minya' => ['المنيا', 'Minya'],
        'benisuef' => ['بني سويف', 'Beni Suef'], 'ismailia' => ['الإسماعيلية', 'Ismailia'], 'borg' => ['برج العرب', 'Borg El Arab'],
        'matrouh' => ['مرسى مطروح', 'Marsa Matrouh'],
    ];

    /** key => [from, to, km, hours, base price, budget per category code] — from the approved demo */
    private const ROUTES = [
        'R1'  => ['obour', 'alex', 450, 17, 10200, ['fuel' => 3450, 'toll' => 260, 'weigh' => 120, 'allow' => 600, 'labor' => 450]],
        'R2'  => ['tenth', 'sokhna', 280, 13, 6900, ['fuel' => 2150, 'toll' => 180, 'weigh' => 120, 'allow' => 400, 'labor' => 400]],
        'R3'  => ['sadat', 'damietta', 460, 17, 10400, ['fuel' => 3550, 'toll' => 240, 'weigh' => 120, 'allow' => 600, 'labor' => 450]],
        'R4'  => ['cairo', 'assiut', 760, 40, 16800, ['fuel' => 5850, 'toll' => 320, 'weigh' => 240, 'allow' => 1100, 'labor' => 500, 'night' => 300]],
        'R5'  => ['october', 'portsaid', 500, 22, 11300, ['fuel' => 3850, 'toll' => 280, 'weigh' => 120, 'allow' => 700, 'labor' => 450]],
        'R6'  => ['obour', 'hurghada', 920, 44, 20400, ['fuel' => 7100, 'toll' => 360, 'weigh' => 240, 'allow' => 1300, 'labor' => 550, 'night' => 400]],
        'R7'  => ['alex', 'minya', 660, 38, 14600, ['fuel' => 5100, 'toll' => 300, 'weigh' => 240, 'allow' => 950, 'labor' => 500, 'night' => 300]],
        'R8'  => ['benisuef', 'cairo', 260, 12, 6200, ['fuel' => 2000, 'toll' => 140, 'weigh' => 120, 'allow' => 380, 'labor' => 350]],
        'R9'  => ['tenth', 'ismailia', 180, 10, 4700, ['fuel' => 1400, 'toll' => 90, 'allow' => 300, 'labor' => 350]],
        'R10' => ['borg', 'matrouh', 500, 22, 11500, ['fuel' => 3850, 'toll' => 200, 'weigh' => 120, 'allow' => 700, 'labor' => 450]],
    ];

    /** [ar, en, price factor, routes, terms days, pays cash] */
    private const CUSTOMERS = [
        ['الشرقية للأسمنت', 'Sharqia Cement', 1.00, ['R8', 'R4', 'R2'], 30, false],
        ['دلتا للصلب', 'Delta Steel', 1.04, ['R3', 'R1', 'R5'], 45, false],
        ['النصر للصناعات الغذائية', 'El Nasr Foods', 0.97, ['R9', 'R1', 'R7'], 0, true],
        ['الوادي للكيماويات', 'El Wadi Chemicals', 1.08, ['R2', 'R6'], 30, false],
        ['المتحدة للأخشاب', 'United Timber', 0.95, ['R1', 'R10', 'R5'], 0, true],
        ['سيناء للرخام', 'Sinai Marble', 1.02, ['R6', 'R4', 'R1'], 30, true],
        ['مطاحن مصر الوسطى', 'Middle Egypt Mills', 0.99, ['R7', 'R4', 'R8'], 0, true],
        ['بورت لاين للحاويات', 'Port Line Containers', 1.03, ['R1', 'R3', 'R5', 'R10'], 30, false],
    ];

    private const DRIVERS = [
        ['سيد فتحي', '01001234568'], ['رضا السيد', '01001234569'], ['أحمد شعبان', '01001234570'],
        ['حسن مصطفى', '01001234571'], ['عادل منصور', '01001234572'], ['إبراهيم رزق', '01001234573'], ['كريم ناصر', '01001234574'],
    ];

    public function run(): void
    {
        $company = Company::query()->where('name_en', 'Nile Heavy Transport')->first();
        if (! $company) {
            return;
        }

        CompanyDefaults::ensure($company->id);
        CompanySetting::for($company->id)->update(['ga_rate_estimate' => 4.50]);

        $categories = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $company->id)->pluck('id', 'code');

        // Routes + standard budgets
        $routes = [];
        foreach (self::ROUTES as $key => [$from, $to, $km, $hours, $price, $budget]) {
            $route = TripRoute::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'origin_en' => self::CITIES[$from][1], 'destination_en' => self::CITIES[$to][1]],
                ['origin_ar' => self::CITIES[$from][0], 'destination_ar' => self::CITIES[$to][0], 'weight_tons' => 25, 'km_round_trip' => $km, 'usual_hours' => $hours, 'is_active' => true],
            );
            foreach ($budget as $code => $amount) {
                TripRouteBudget::query()->withoutGlobalScopes()->firstOrCreate(
                    ['trip_route_id' => $route->id, 'expense_category_id' => $categories[$code]],
                    ['company_id' => $company->id, 'amount' => $amount],
                );
            }
            $routes[$key] = [$route, $price];
        }

        // Customers + rate cards (Sinai Marble already exists from DemoSeeder)
        foreach (self::CUSTOMERS as [$ar, $en, $factor, $keys, $terms, $cash]) {
            $customer = Customer::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name_en' => $en],
                ['name_ar' => $ar, 'payment_terms_days' => $terms, 'may_pay_driver_cash' => $cash, 'is_active' => true],
            );
            foreach ($keys as $key) {
                [$route, $price] = $routes[$key];
                RateCard::query()->withoutGlobalScopes()->firstOrCreate(
                    ['customer_id' => $customer->id, 'trip_route_id' => $route->id],
                    ['company_id' => $company->id, 'price' => round($price * $factor / 50) * 50],
                );
            }
        }

        // Drivers
        $drivers = Driver::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->get()->all();
        foreach (self::DRIVERS as $i => [$name, $mobile]) {
            $drivers[] = Driver::query()->withoutGlobalScopes()->firstOrCreate(['mobile' => $mobile], [
                'company_id' => $company->id, 'name' => $name, 'pin' => '1234',
                'license_number' => 'DL-'.(310000 + $i * 717), 'license_expires_at' => $i === 5 ? today()->addDays(12) : today()->addMonths(8 + $i * 3),
                'pay_basis' => Driver::PAY_BASES[$i % 3], 'base_salary' => [6500, 7000, 7500, 8000][$i % 4],
                'joined_at' => today()->subYears(1 + $i), 'is_active' => true,
            ]);
        }

        // Vehicles: 10 own (one driver each where possible) + 2 hired
        $models = ['Mercedes Actros 2644', 'MAN TGS 33.440', 'Volvo FH 460', 'Mercedes Axor 1843', 'Scania R450'];
        $letters = ['ن ق ل', 'ن ع ط', 'ن ب ر', 'ن س ق', 'ن و ه', 'ن ج م', 'ن ط ل', 'ن ر ف', 'ن ق ع', 'ن ب ع'];
        $types = ['tractor_trailer', 'tractor_trailer', 'extra_heavy', 'tractor_trailer', 'heavy', 'tanker', 'extra_heavy', 'tractor_trailer', 'heavy', 'tractor_trailer'];
        $typeIds = \App\Models\VehicleType::query()->withoutGlobalScopes()->where('company_id', $company->id)->pluck('id', 'code');

        foreach (range(0, 9) as $i) {
            Vehicle::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'plate_number' => (string) (2140 + $i * 613), 'plate_letters' => $letters[$i]],
                [
                    'vehicle_type_id' => $typeIds[$types[$i]], 'model' => $models[$i % 5], 'year' => 2016 + $i % 8, 'capacity_tons' => [40, 30, 25][$i % 3],
                    'ownership' => 'own', 'driver_id' => $drivers[$i]->id ?? null,
                    'odometer_km' => 210000 + $i * 41000, 'std_km_per_litre' => 2.45 + ($i % 4) * 0.08,
                    'status' => $i === 7 ? 'maintenance' : 'available',
                    'licence_number' => 'VL-'.(88000 + $i), 'licence_expires_at' => $i === 3 ? today()->addDays(17) : today()->addMonths(6 + $i),
                    'insurance_company' => 'مصر للتأمين', 'insurance_policy_number' => 'MI-'.(5500 + $i),
                    'insurance_expires_at' => $i === 5 ? today()->subDays(4) : today()->addMonths(4 + $i),
                    'inspection_expires_at' => $i === 8 ? today()->addDays(25) : today()->addMonths(9 + $i),
                ],
            );
        }

        foreach ([['7310', 'ر ج ب', 'المعلم رجب حماد'], ['8452', 'م ن ة', 'شركة الأمانة للنقل']] as [$number, $plate, $owner]) {
            Vehicle::query()->withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'plate_number' => $number, 'plate_letters' => $plate],
                ['vehicle_type_id' => $typeIds['tractor_trailer'], 'model' => 'Mercedes Actros', 'year' => 2018, 'ownership' => 'hired', 'owner_name' => $owner, 'owner_phone' => '01112223344', 'status' => 'available'],
            );
        }

        $this->command?->info('Step 2 demo data: routes, customers & rate cards, drivers, vehicles.');
    }
}
