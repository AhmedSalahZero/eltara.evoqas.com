<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Driver;
use App\Models\FuelEntry;
use App\Models\GaEntry;
use App\Models\Invoice;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Advances\AdvanceService;
use App\Services\Closing\MonthCloseService;
use App\Services\Fuel\FuelService;
use App\Services\Invoices\InvoiceService;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Step6Seeder (Step 6 demo data)
//  Location: database/seeders/Step6Seeder.php
//
//  Gives "Nile Heavy Transport" something to look at on the four new
//  screens:
//    · Fuel log      refuels for four own trucks over the last weeks
//                    (one truck drinks more than its standard, so it
//                    shows the "possible over-draw" flag);
//    · Advances      two advances given by the office, one with an
//                    instalment and a cash repayment;
//    · Invoices      one invoice covering two settled trips of the same
//                    customer, one covering a single trip — the rest of
//                    the settled trips stay "without an invoice";
//    · Month close   last month's G&A lines, left OPEN so you can close
//                    the month yourself and watch the true profit move.
//  Also ticks the new permissions for the two demo office users
//  (existing users keep what they had, so we add to it).
//  Every part is separate and careful: if one cannot run it says so
//  and the others still run. Runs once; safe to run again.
// ══════════════════════════════════════════════════════════════════

class Step6Seeder extends Seeder
{
    private User $admin;

    public function run(): void
    {
        $company = Company::query()->where('name_en', 'Nile Heavy Transport')->first();
        if (! $company) {
            return;
        }

        $this->admin = User::query()->where('company_id', $company->id)->where('role', UserRole::CompanyAdmin->value)->first();
        if (! $this->admin) {
            return;
        }

        $this->permissions($company);

        Tenant::forCompany($company->id, function () {
            foreach (['fuel' => 'Fuel log', 'advances' => 'Driver advances', 'invoices' => 'Invoice numbers', 'ga' => 'G&A of last month'] as $part => $label) {
                try {
                    $this->{$part}();
                } catch (\Throwable $e) {
                    $this->command?->warn("Step 6 demo — {$label}: skipped ({$e->getMessage()})");
                }
            }
        });

        Carbon::setTestNow();
        $this->command?->info('Step 6 demo data: fuel, advances, invoice numbers, last month\'s G&A (left open).');
    }

    /** Adds the Step 6 permissions to the demo office users. */
    private function permissions(Company $company): void
    {
        $add = [
            'mona@nile-transport.test'  => ['fuel.view', 'fuel.create', 'fuel.edit', 'driver_advances.view', 'driver_advances.create'],
            'karim@nile-transport.test' => ['fuel.view', 'driver_advances.view', 'driver_advances.edit', 'driver_advances.approve', 'invoice_links.view', 'invoice_links.create', 'invoice_links.edit', 'month_close.view'],
        ];

        foreach ($add as $email => $keys) {
            $user = User::query()->where('company_id', $company->id)->where('email', $email)->first();
            if ($user) {
                $user->forceFill(['permissions' => array_values(array_unique(array_merge($user->permissions ?? [], $keys)))])->save();
            }
        }
    }

    private function fuel(): void
    {
        if (FuelEntry::query()->exists()) {
            return;
        }

        $service = app(FuelService::class);
        // The demo uses the company's own diesel price (the default, 20.50), so the demo figures agree with the settings screen.
        $price = (float) (CompanySetting::for(Tenant::id())->diesel_price ?: 20.50);
        $trucks = Vehicle::query()->where('ownership', 'own')->orderBy('id')->limit(4)->get();

        foreach ($trucks as $i => $truck) {
            $standard = (float) ($truck->std_km_per_litre ?: 3.2);
            $factor = $i === 1 ? 1.25 : 1.0;          // truck no. 2 drinks 25% more than it should
            $odometer = 120000 + $truck->id * 1500;

            foreach ([24, 17, 10, 3] as $n => $daysAgo) {
                $km = 640 + $n * 40;
                $odometer += $km;
                $litres = $n === 0 ? 180 : round($km / $standard * $factor, 1);
                $service->record([
                    'vehicle_id'      => $truck->id,
                    'driver_id'       => $truck->driver_id,
                    'filled_at'       => now()->subDays($daysAgo)->setTime(7 + $i, 15)->toDateTimeString(),
                    'litres'          => $litres,
                    'price_per_litre' => $price,
                    'odometer_km'     => $odometer,
                    'station'         => $i % 2 ? 'Misr Petrol — Suez road' : 'Total — Cairo/Alex desert road',
                    'paid_by'         => 'card',
                ], $this->admin);
            }
        }
    }

    private function advances(): void
    {
        if (\App\Models\DriverAdvance::query()->where('source', 'manual')->exists()) {
            return;
        }

        $service = app(AdvanceService::class);
        $drivers = Driver::query()->where('is_active', true)->orderBy('id')->limit(2)->get();

        if ($drivers->count() >= 1) {
            $a = $service->create($drivers[0], 4000, 'Family emergency', 1000, $this->admin);
            $service->repayCash($a, 500, 'Handed in at the office', $this->admin);
        }
        if ($drivers->count() >= 2) {
            $service->create($drivers[1], 1500, 'Phone repair', null, $this->admin);
        }
    }

    private function invoices(): void
    {
        if (Invoice::query()->exists()) {
            return;
        }

        $service = app(InvoiceService::class);
        $settled = Trip::query()->where('status', 'settled')->whereNull('invoice_id')->orderBy('settled_at')->get()->groupBy('customer_id');

        // One invoice covering two settled trips of the same customer …
        $group = $settled->first(fn ($trips) => $trips->count() >= 2);
        $used = [];
        if ($group) {
            $pair = $group->take(2);
            $service->link((int) $pair->first()->customer_id, 'INV-2026-0141', now()->subDays(8)->toDateString(), 'Two trips, one invoice', $pair->pluck('id')->all(), $this->admin);
            $used = $pair->pluck('id')->all();
        }

        // … and one invoice for a single trip of someone else (or the same customer if there is no one else).
        $single = $settled->flatten()->first(fn (Trip $t) => ! in_array($t->id, $used, true) && (! $group || $t->customer_id !== $group->first()->customer_id))
            ?? $settled->flatten()->first(fn (Trip $t) => ! in_array($t->id, $used, true));
        if ($single) {
            $service->link((int) $single->customer_id, 'INV-2026-0142', now()->subDays(3)->toDateString(), null, [$single->id], $this->admin);
        }
    }

    private function ga(): void
    {
        if (GaEntry::query()->exists()) {
            return;
        }

        $service = app(MonthCloseService::class);
        $month = Carbon::now()->subMonthNoOverflow()->startOfMonth();

        foreach ([['driver_salaries', 48000], ['office_salaries', 36000], ['depreciation', 22000], ['insurance', 7500], ['rent', 12000], ['other_admin', 4500]] as [$code, $amount]) {
            $service->addLine($month, ['code' => $code, 'amount' => $amount], $this->admin);
        }
    }
}
