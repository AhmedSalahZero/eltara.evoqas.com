<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\MaintenanceJob;
use App\Models\StockItem;
use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\TripCrew;
use App\Models\TripRoute;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Stock\StockService;
use App\Services\Trips\CollectionService;
use App\Services\Trips\CrewService;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  El Tara — LatestFeaturesSeeder (demo data for the newest features)
//  Location: database/seeders/LatestFeaturesSeeder.php
//
//  Gives "Nile Heavy Transport" something to look at for:
//    · Route hours     each route's time split into DRIVING hours and
//                      WAITING hours (loading + unloading);
//    · Workshop store  the company workshop is on, with oil and filters in
//                      stock (two receipts at different prices);
//    · Maintenance     finished services (an oil change of 5,000 EGP for
//                      10,000 km = 0.5 EGP per km, carried by the truck's
//                      trips), two separate "Other" items on one truck
//                      (brakes and gearbox oil), services that are due soon
//                      or overdue, a job done from the workshop store, and
//                      an open breakdown that holds a truck;
//    · Crew            a second driver and assistants on open trips, with
//                      their allowances (posted from the main driver's
//                      custody once loading has started; waiting before);
//    · Client cash     one amount the client could not confirm himself,
//                      confirmed by the office on his behalf (another one is
//                      left waiting so the button can be tried).
//  Every part is separate and careful: if one cannot run it says so and the
//  others still run. Safe to run again (each part runs only once).
//  Needs the Step 2, 3 and 6 demo data first (DemoSeeder runs them).
// ══════════════════════════════════════════════════════════════════

class LatestFeaturesSeeder extends Seeder
{
    /** km of the route (round trip) => [driving hours, waiting hours]; driving + waiting = the old "usual hours". */
    private const SPLITS = [
        450 => [8.5, 8.5], 280 => [5.5, 7.5], 460 => [9.0, 8.0], 760 => [16.0, 24.0], 500 => [10.0, 12.0],
        920 => [18.0, 26.0], 660 => [13.0, 25.0], 260 => [5.0, 7.0], 180 => [3.5, 6.5],
    ];

    private User $admin;

    public function run(): void
    {
        $company = Company::query()->where('name_en', 'Nile Heavy Transport')->first();
        if (! $company) {
            return;
        }

        $admin = User::query()->where('company_id', $company->id)->where('role', UserRole::CompanyAdmin->value)->first();
        if (! $admin) {
            return;
        }
        $this->admin = $admin;

        Tenant::forCompany($company->id, function () use ($company) {
            $parts = [
                'hours' => 'Route driving + waiting hours',
                'workshop' => 'Workshop store',
                'maintenance' => 'Maintenance',
                'crew' => 'Second driver / assistant',
                'cash' => 'Client cash confirmed by the office',
            ];
            foreach ($parts as $part => $label) {
                try {
                    $this->{$part}($company);
                } catch (\Throwable $e) {
                    $this->command?->warn("Latest features demo — {$label}: skipped ({$e->getMessage()})");
                }
            }
        });

        Carbon::setTestNow();
        $this->command?->info('Latest features demo: route hours, workshop store, maintenance, crew, client cash confirmed by the office.');
    }

    /** Driving and waiting hours on the routes that still hold only the old single figure. */
    private function hours(Company $company): void
    {
        foreach (TripRoute::query()->whereNull('wait_hours')->get() as $route) {
            $split = self::SPLITS[$route->km_round_trip] ?? null;
            if (! $split || abs((float) $route->usual_hours - array_sum($split)) > 0.01) {
                continue; // not a demo route, or someone already changed it
            }

            [$driving, $waiting] = $split;
            $route->forceFill(['usual_hours' => $driving, 'wait_hours' => $waiting])->save();
            Trip::query()->where('trip_route_id', $route->id)->update(['road_hours' => $driving, 'planned_hours' => $driving + $waiting]);
        }
    }

    private function workshop(Company $company): void
    {
        if (StockItem::query()->exists()) {
            return;
        }

        CompanySetting::for($company->id)->update(['has_workshop' => true]);
        $stock = app(StockService::class);
        $store = $stock->ensureDefault($company->id);

        $items = [];
        foreach ([['زيت محرك 15W-40', 'litre'], ['فلتر زيت', 'piece'], ['فلتر هواء', 'piece']] as [$name, $unit]) {
            $items[$name] = $stock->addItem(['name' => $name, 'unit' => $unit], $this->admin);
        }

        // Two receipts of the oil at different prices: the store works out the average cost.
        foreach ([
            ['زيت محرك 15W-40', 200, 52, 30], ['زيت محرك 15W-40', 100, 58, 8],
            ['فلتر زيت', 30, 340, 30], ['فلتر هواء', 20, 520, 30],
        ] as [$name, $qty, $price, $daysAgo]) {
            $stock->receive([
                'store_id' => $store->id, 'stock_item_id' => $items[$name]->id, 'qty' => $qty, 'unit_price' => $price,
                'supplier' => 'شركة الوطنية للزيوت وقطع الغيار', 'occurred_at' => now()->subDays($daysAgo)->setTime(11, 0)->toDateTimeString(),
            ], $this->admin);
        }
    }

    private function maintenance(Company $company): void
    {
        if (MaintenanceJob::query()->exists()) {
            return;
        }

        $service = app(MaintenanceService::class);
        $trucks = Vehicle::query()->where('ownership', 'own')->orderBy('id')->get()->values();
        if ($trucks->count() < 8) {
            throw new \RuntimeException('needs the demo trucks (MasterDataSeeder)');
        }

        $day = fn (int $daysAgo, string $time) => now()->subDays($daysAgo)->format('Y-m-d').' '.$time;
        $odo = fn (int $i) => (int) $trucks[$i]->odometer_km;

        $jobs = [
            // Oil change 5,000 EGP serving 10,000 km: each trip of this truck carries 0.5 EGP per km.
            [0, [
                'kind' => 'periodic', 'category' => 'oil', 'status' => 'done', 'opened_at' => $day(2, '09:00'), 'completed_at' => $day(2, '14:00'),
                'odometer_km' => $odo(0) - 2500, 'cost' => 5000, 'workshop' => 'ورشة النيل للصيانة', 'interval_km' => 10000, 'interval_days' => 90,
            ]],
            // Two different "Other" items on one truck: brakes (soon, by km) and gearbox oil (overdue, by date).
            [1, [
                'kind' => 'periodic', 'category' => 'other', 'note' => 'تيل الفرامل', 'status' => 'done', 'opened_at' => $day(70, '09:00'), 'completed_at' => $day(70, '15:00'),
                'odometer_km' => $odo(1) - 19800, 'cost' => 3200, 'workshop' => 'ورشة الأمانة', 'interval_km' => 20000,
            ]],
            [1, [
                'kind' => 'periodic', 'category' => 'other', 'note' => 'زيت الجيربوكس', 'status' => 'done', 'opened_at' => $day(100, '09:00'), 'completed_at' => $day(100, '13:00'),
                'cost' => 1800, 'workshop' => 'ورشة الأمانة', 'interval_days' => 90,
            ]],
            // Oil change due in five days.
            [2, [
                'kind' => 'periodic', 'category' => 'oil', 'status' => 'done', 'opened_at' => $day(85, '09:00'), 'completed_at' => $day(85, '12:00'),
                'odometer_km' => $odo(2) - 9000, 'cost' => 4200, 'workshop' => 'ورشة النيل للصيانة', 'interval_km' => 15000, 'interval_days' => 90,
            ]],
            // Tyres: a long pool (24,000 EGP over 60,000 km) carried by the truck's loading and planned trips.
            [3, [
                'kind' => 'periodic', 'category' => 'tyres', 'status' => 'done', 'opened_at' => $day(30, '08:00'), 'completed_at' => $day(30, '16:00'),
                'odometer_km' => $odo(3) - 1500, 'cost' => 24000, 'workshop' => 'مركز الإطارات الحديثة', 'interval_km' => 60000,
            ]],
            // The truck that was already marked "in maintenance": an open breakdown now explains why.
            [7, [
                'kind' => 'emergency', 'category' => 'other', 'status' => 'open', 'opened_at' => $day(2, '08:00'),
                'note' => 'تعطل ناقل الحركة (الجيربوكس) — في انتظار قطعة الغيار', 'workshop' => 'ورشة الأمانة',
            ]],
        ];

        // A job done from the company's own store: the parts come out of stock and the cost is what they cost.
        if (StockItem::query()->exists()) {
            $store = app(StockService::class)->ensureDefault($company->id);
            $id = fn (string $name) => (int) StockItem::query()->where('name', $name)->value('id');
            $jobs[] = [5, [
                'kind' => 'periodic', 'category' => 'oil', 'status' => 'done', 'source' => 'own', 'store_id' => $store->id,
                'opened_at' => $day(10, '08:00'), 'completed_at' => $day(10, '11:00'), 'odometer_km' => $odo(5) - 4000, 'interval_km' => 12000,
                'lines' => [['item_id' => $id('زيت محرك 15W-40'), 'qty' => 38], ['item_id' => $id('فلتر زيت'), 'qty' => 1], ['item_id' => $id('فلتر هواء'), 'qty' => 1]],
            ]];
        }

        foreach ($jobs as [$i, $data]) {
            $service->record(['vehicle_id' => $trucks[$i]->id] + $data, $this->admin);
        }
    }

    private function crew(Company $company): void
    {
        if (TripCrew::query()->exists()) {
            return;
        }

        $crew = app(CrewService::class);
        $trucks = Vehicle::query()->where('ownership', 'own')->orderBy('id')->get()->values();
        $trip = fn (int $i, string $status) => Trip::query()->where('vehicle_id', $trucks[$i]->id ?? 0)->where('status', $status)->orderBy('id')->first();

        $plan = [
            // A long night trip: a second driver and an assistant (posted from the main driver's custody).
            [$trip(1, 'on_road'), [['second_driver', 'عبدالله مصطفى', 600, 'سائق ثانٍ للرحلة الطويلة'], ['assistant', 'علي حسن', 300, null]]],
            [$trip(2, 'on_road'), [['assistant', 'يوسف إبراهيم', 250, null]]],
            // Not started yet: recorded, allowance waits until loading starts.
            [$trip(4, 'accepted'), [['assistant', 'مصطفى كامل', 250, null]]],
            [$trip(3, 'planned'), [['assistant', 'محمد جمال', 200, null]]],
        ];

        foreach ($plan as [$t, $people]) {
            if (! $t) {
                continue;
            }
            foreach ($people as [$role, $name, $allowance, $note]) {
                $crew->add($t->fresh(), ['role' => $role, 'name' => $name, 'allowance' => $allowance, 'note' => $note], $this->admin);
            }
        }
    }

    private function cash(Company $company): void
    {
        if (TripCollection::query()->whereNotNull('client_confirmed_for_by')->exists()) {
            return;
        }

        $truck = Vehicle::query()->where('ownership', 'own')->orderBy('id')->skip(1)->first();
        $trip = $truck ? Trip::query()->where('vehicle_id', $truck->id)->where('status', 'on_road')->orderBy('id')->first() : null;
        $waiting = $trip ? TripCollection::query()->where('trip_id', $trip->id)->get()->first(fn (TripCollection $c) => $c->state() === 'awaiting_client') : null;

        if ($waiting) {
            app(CollectionService::class)->confirmForClient($waiting, $this->admin, 'العميل ليس لديه رابط التطبيق — أكّد استلام المبلغ هاتفياً');
        }
    }
}
