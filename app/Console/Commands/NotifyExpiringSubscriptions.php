<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\SubscriptionEndingNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// ══════════════════════════════════════════════════════════════════
//  El Tara — subscriptions:notify-expiring
//  Location: app/Console/Commands/NotifyExpiringSubscriptions.php
//
//  Runs daily (routes/console.php). Emails the admin of every active
//  or trial company whose subscription ends within 30 days (Scope
//  §4.2) — at most once a week per company (config/eltara.php),
//  tracked in companies.expiry_notified_at.
//  The in-app banner shows the same warning every day regardless.
//  A company with no activated admin is listed in the output.
// ══════════════════════════════════════════════════════════════════

class NotifyExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:notify-expiring';

    protected $description = 'Email company admins whose El Tara subscription ends soon';

    public function handle(): int
    {
        $window = (int) config('eltara.subscription.notify_days_before', 30);
        $repeat = (int) config('eltara.subscription.notify_again_after_days', 7);

        $companies = Company::query()
            ->where('status', '!=', CompanyStatus::Suspended->value)
            ->whereNotNull('subscription_ends_at')
            ->whereDate('subscription_ends_at', '>=', today())
            ->whereDate('subscription_ends_at', '<=', today()->addDays($window))
            ->where(fn ($q) => $q->whereNull('expiry_notified_at')->orWhere('expiry_notified_at', '<=', now()->subDays($repeat)))
            ->get();

        $emailed = 0;

        foreach ($companies as $company) {
            $admins = User::query()
                ->where('company_id', $company->id)
                ->where('role', UserRole::CompanyAdmin->value)
                ->where('is_active', true)
                ->whereNotNull('email_verified_at')
                ->get();

            if ($admins->isEmpty()) {
                $this->warn("Company #{$company->id} ({$company->name_en}) has no activated admin to notify.");

                continue;
            }

            foreach ($admins as $admin) {
                try {
                    $admin->notify(new SubscriptionEndingNotification($company, (int) $company->daysUntilExpiry()));
                    $emailed++;
                } catch (\Throwable $e) {
                    Log::error('Subscription reminder failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
                }
            }

            $company->forceFill(['expiry_notified_at' => now()])->save();
        }

        $this->info("Subscription reminders: emailed {$emailed} admin(s) of {$companies->count()} company(ies).");

        return self::SUCCESS;
    }
}
