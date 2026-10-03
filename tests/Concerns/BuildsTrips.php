<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\ExpenseCategory;
use App\Models\RateCard;
use App\Models\Trip;
use App\Models\TripRoute;
use App\Models\TripRouteBudget;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyDefaults;
use App\Services\Trips\Actor;
use App\Services\Trips\TripService;
use App\Services\Trips\WalletLedger;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test helpers: a ready company with a trip (Step 3)
//  Location: tests/Concerns/BuildsTrips.php
//
//  setUpFleet()  → a company with its admin, the 10 standard expense
//                  categories, the route "Obour → Alexandria Port"
//                  (450 km; budget fuel 3,450 · tolls 260 · weigh 120
//                  · allowance 600 · labour 450 → suggested custody
//                  3,000), Delta Steel's price 10,200, a truck and its
//                  driver.
//  runningTrip() → that set-up plus a trip already on the road with
//                  3,000 custody handed over.
//  balances()    → the trip's four wallet balances from the ledger.
//  Used by TripsTest, WalletsTest and SettlementTest.
// ══════════════════════════════════════════════════════════════════

trait BuildsTrips
{
    protected Company $co;

    protected User $admin;

    protected TripRoute $route;

    protected Customer $customer;

    protected Vehicle $truck;

    protected Driver $drv;

    /** @var array<string,int> */
    protected array $cats = [];

    protected function setUpFleet(array $settings = []): void
    {
        $this->co = $this->company();
        $this->admin = $this->companyAdmin($this->co);
        CompanyDefaults::ensure($this->co->id);
        if ($settings) {
            CompanySetting::for($this->co->id)->update($settings);
        }

        $this->cats = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $this->co->id)->pluck('id', 'code')->all();

        $this->route = TripRoute::factory()->for($this->co)->create();
        foreach (['fuel' => 3450, 'toll' => 260, 'weigh' => 120, 'allow' => 600, 'labor' => 450] as $code => $amount) {
            TripRouteBudget::query()->create(['company_id' => $this->co->id, 'trip_route_id' => $this->route->id, 'expense_category_id' => $this->cats[$code], 'amount' => $amount]);
        }

        $this->customer = Customer::factory()->for($this->co)->create(['name_en' => 'Delta Steel', 'may_pay_driver_cash' => true]);
        RateCard::query()->create(['company_id' => $this->co->id, 'customer_id' => $this->customer->id, 'trip_route_id' => $this->route->id, 'price' => 10200]);

        $this->drv = $this->driver($this->co);
        $this->truck = Vehicle::factory()->for($this->co)->create(['driver_id' => $this->drv->id]);
    }

    /** The form fields of a new trip on the set-up route. */
    protected function tripData(array $overrides = []): array
    {
        return array_merge([
            'customer_id'     => $this->customer->id,
            'trip_route_id'   => $this->route->id,
            'vehicle_id'      => $this->truck->id,
            'loading_at'      => now()->addHours(2)->format('Y-m-d H:i'),
            'cargo_type_id'   => \App\Models\CargoType::query()->withoutGlobalScopes()->firstOrCreate(['company_id' => $this->co->id, 'name_ar' => 'Steel bars'])->id,
            'weight_tons'     => 28.5,
            'transfer_policy' => 'limit',
            'client_pays_cash'=> true,
        ], $overrides);
    }

    /** A trip on the road with 3,000 custody handed over, made through the real services. */
    protected function runningTrip(string $policy = 'limit', float $limit = 1000, array $settings = []): Trip
    {
        $this->setUpFleet($settings);

        return Tenant::forCompany($this->co->id, function () use ($policy, $limit) {
            $service = app(TripService::class);
            $trip = $service->create($this->tripData(['transfer_policy' => $policy, 'auto_transfer_limit' => $limit]), $this->admin);
            $actor = Actor::user($this->admin);
            $service->accept($trip, $actor);
            $service->issueCustody($trip, 3000, $actor);
            $service->startLoading($trip, $actor);
            $service->depart($trip, $actor);

            return $trip->fresh();
        });
    }

    /** @return array{custody: float, collections: float, advances: float, pocket: float} */
    protected function balances(Trip $trip): array
    {
        return Tenant::forCompany($trip->company_id, fn () => app(WalletLedger::class)->tripBalances($trip->id));
    }

    protected function pod(): UploadedFile
    {
        return UploadedFile::fake()->image('delivery-note.jpg', 600, 800);
    }
}
