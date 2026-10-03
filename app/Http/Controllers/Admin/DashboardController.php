<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Admin\DashboardController ( /admin — Platform overview )
//  Location: app/Http/Controllers/Admin/DashboardController.php
//
//  Scope §4.2 Super Admin screens: active companies, office users and
//  driver accounts against their limits, companies at a limit (an
//  upsell opportunity), subscriptions ending soon — and the companies
//  list with usage meters. Only counts and limits: never a company's
//  financial data.
//  All figures come from a few grouped queries, so the page stays
//  fast with 500 companies.
// ══════════════════════════════════════════════════════════════════

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $companies = Company::query()
            ->withUsage()
            ->where('status', '!=', CompanyStatus::Suspended->value)
            ->get();

        $soon = now()->addDays((int) config('eltara.subscription.notify_days_before', 30))->toDateString();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'active'          => $companies->count(),
                'trial'           => $companies->where('status', CompanyStatus::Trial)->count(),
                'office_used'     => (int) $companies->sum('office_used'),
                'office_allowed'  => (int) $companies->sum('office_users_limit'),
                'drivers_used'    => (int) $companies->sum('drivers_used'),
                'drivers_allowed' => (int) $companies->sum('driver_accounts_limit'),
                'at_limit'        => $companies->filter(fn ($c) => $c->office_used >= $c->office_users_limit || $c->drivers_used >= $c->driver_accounts_limit)->count(),
                'ending_soon'     => $companies->filter(fn ($c) => $c->subscription_ends_at && $c->subscription_ends_at->toDateString() <= $soon)->count(),
            ],
            'defaults'  => CompanyController::defaults(),
            'companies' => CompanyController::rows(
                Company::query()->withUsage()->with('admin')->latest()->limit(10)->get()
            ),
        ]);
    }
}
