<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\Notifier;
use App\Support\DocumentExpiry;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════
//  El Tara — documents:notify-expiring
//  Location: app/Console/Commands/NotifyExpiringDocuments.php
//
//  Scope §11 "Document expiring within 30 days → Office and driver".
//  Runs daily (routes/console.php). For every active company it puts
//  one line in the bell of the office users who may see trucks /
//  drivers, when a document is exactly 30, 14, 7, 3, 1 or 0 days from
//  its end — so a document is announced a few times, not every
//  morning. (The drivers see the same documents in their own app,
//  built from their phone's data — see driver/ alerts.)
//  Calling it twice on one day does not repeat the lines.
// ══════════════════════════════════════════════════════════════════

class NotifyExpiringDocuments extends Command
{
    /** Days before the end on which a line is sent. */
    public const STEPS = [30, 14, 7, 3, 1, 0];

    protected $signature = 'documents:notify-expiring';

    protected $description = 'Put a bell line for office users when a truck or driver document is about to expire';

    public function handle(Notifier $notifier): int
    {
        $sent = 0;

        foreach (Company::query()->where('status', '!=', CompanyStatus::Suspended->value)->get() as $company) {
            $sent += Tenant::forCompany($company->id, fn () => $this->company($company, $notifier));
        }

        $this->info("Document alerts sent: {$sent}.");

        return self::SUCCESS;
    }

    private function company(Company $company, Notifier $notifier): int
    {
        $sent = 0;

        foreach (Vehicle::query()->where('ownership', 'own')->get() as $vehicle) {
            foreach (Vehicle::DOCUMENTS as $doc => $column) {
                $sent += $this->check($company->id, 'vehicle:'.$vehicle->id.':'.$doc, $vehicle->$column, 'vehicles.view', [
                    'owner' => $vehicle->plateText(), 'document' => $doc,
                ], route('office.vehicles.show', $vehicle, false), $notifier);
            }
        }

        foreach (Driver::query()->where('is_active', true)->get() as $driver) {
            $sent += $this->check($company->id, 'driver:'.$driver->id, $driver->license_expires_at, 'drivers.view', [
                'owner' => $driver->name, 'document' => 'driving',
            ], route('office.drivers.show', $driver, false), $notifier);
        }

        return $sent;
    }

    private function check(int $companyId, string $key, $date, string $permission, array $params, string $url, Notifier $notifier): int
    {
        $d = DocumentExpiry::describe($date);
        if ($d['days'] === null || ! in_array($d['days'], self::STEPS, true)) {
            return 0;
        }
        // One line per document per step, even if the command runs twice.
        if (! Cache::add("doc-alert:{$companyId}:{$key}:{$d['days']}:{$d['date']}", 1, now()->addDays(2))) {
            return 0;
        }

        $notifier->toOffice($companyId, $permission, 'document.expiring', $params + ['days' => $d['days'], 'date' => $d['date']], $url);

        return 1;
    }
}
