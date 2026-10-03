<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Driver;
use App\Models\Trip;
use App\Services\Notifier;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════
//  El Tara — drivers:notify-unsynced
//  Location: app/Console/Commands/NotifyUnsyncedDrivers.php
//
//  Scope §11 "Driver app not synced beyond the set time → Office".
//  Runs every hour (routes/console.php). A driver who has a trip on
//  the road (or loading) and whose phone has not uploaded for longer
//  than the company's "max hours without sync" (Company settings)
//  puts ONE line in the bell of the users who may see drivers — once
//  per silence, not every hour. Phones that sync again start fresh.
// ══════════════════════════════════════════════════════════════════

class NotifyUnsyncedDrivers extends Command
{
    protected $signature = 'drivers:notify-unsynced';

    protected $description = 'Tell the office when a driver on the road has not synced for too long';

    public function handle(Notifier $notifier): int
    {
        $sent = 0;

        foreach (Company::query()->where('status', '!=', CompanyStatus::Suspended->value)->get() as $company) {
            $sent += Tenant::forCompany($company->id, function () use ($company, $notifier) {
                $hours = (int) (CompanySetting::for($company->id)->max_hours_without_sync ?: 24);
                $onRoad = Trip::query()->whereIn('status', ['loading', 'on_road'])->whereNotNull('driver_id')->pluck('driver_id')->unique()->all();
                $count = 0;

                foreach (Driver::query()->where('is_active', true)->whereIn('id', $onRoad ?: [0])->get() as $driver) {
                    $last = $driver->last_sync_at;
                    if ($last && $last->greaterThan(now()->subHours($hours))) {
                        continue;
                    }
                    // Once per silence: the key holds the moment of the last sync it was announced for.
                    if (! Cache::add("unsynced:{$company->id}:{$driver->id}:".($last?->timestamp ?? 0), 1, now()->addDays(14))) {
                        continue;
                    }

                    $notifier->toOffice($company->id, 'drivers.view', 'driver.unsynced', [
                        'driver' => $driver->name, 'hours' => $hours, 'since' => $last?->toIso8601String(),
                    ], route('office.drivers.show', $driver, false));
                    $count++;
                }

                return $count;
            });
        }

        $this->info("Unsynced alerts sent: {$sent}.");

        return self::SUCCESS;
    }
}
