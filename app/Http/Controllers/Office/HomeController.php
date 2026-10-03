<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\ClientComplaint;
use App\Models\ClientRequest;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\WalletTransfer;
use App\Services\Dashboard\DashboardService;
use App\Support\DocumentExpiry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\HomeController ( /office )
//  Location: app/Http/Controllers/Office/HomeController.php
//
//  The office start page. Users with "dashboard.view" get the full
//  dashboard (Step 7, Scope §6.1 — Office/Dashboard.vue, figures from
//  App\Services\Dashboard\DashboardService). Everyone else gets the
//  simple start page below: the company's
//  subscription, the two limits in use, documents needing attention
//  (Step 2), trips and wallets waiting for someone (Step 3), and the
//  build road map.
// ══════════════════════════════════════════════════════════════════

class HomeController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user('web');

        // Scope §6.1: the dashboard for everyone who may see it; the simple start page for the rest.
        if ($user->can('dashboard.view')) {
            return Inertia::render('Office/Dashboard', [
                'dash'  => $this->dashboard->build($user->company_id, DashboardService::period($request->query('period'))),
                // The small counts of the simple start page stay available (cheap; e.g. approved requests waiting for trucks).
                'money' => $this->money($request),
            ]);
        }

        $company = $user->company;

        return Inertia::render('Office/Home', [
            'limits' => [
                'office_used'    => $company->officeSeatsUsed(),
                'office_limit'   => $company->office_users_limit,
                'drivers_used'   => $company->driverSeatsUsed(),
                'drivers_limit'  => $company->driver_accounts_limit,
            ],
            'attention' => $this->attention($request),
            'money'     => $this->money($request),
            'subscription' => [
                'status'    => $company->status->value,
                'starts_at' => $company->subscription_starts_at?->toDateString(),
                'ends_at'   => $company->subscription_ends_at?->toDateString(),
                'days_left' => $company->daysUntilExpiry(),
            ],
        ]);
    }

    /** Transfers waiting, automatic ones to review, delivered trips to settle (Step 3), client requests and complaints (Step 5). */
    private function money(Request $request): array
    {
        $user = $request->user('web');
        $wallets = $user->can('wallet_transfers.view');

        return [
            'transfers' => $wallets ? WalletTransfer::query()->where('status', 'pending')->count() : null,
            'review'    => $wallets ? WalletTransfer::query()->where('status', 'auto')->whereNull('reviewed_at')->count() : null,
            'settle'    => $user->can('trips.view') ? Trip::query()->where('status', 'delivered')->count() : null,
            // Step 5: what clients are waiting for
            'requests'   => $user->can('client_requests.view') ? ClientRequest::query()->where('status', 'new')->count() : null,
            'to_assign'  => $user->can('client_requests.view') ? ClientRequest::query()->where('status', 'approved')->count() : null,
            'complaints' => $user->can('client_requests.view') ? ClientComplaint::query()->where('status', 'open')->count() : null,
        ];
    }

    /** How many vehicle documents and driving licences are expired or end within 30 days. */
    private function attention(Request $request): array
    {
        $user = $request->user('web');
        $limit = today()->addDays(DocumentExpiry::alertDays())->toDateString();

        return [
            'vehicles' => $user->can('vehicles.view') ? Vehicle::query()->where('ownership', 'own')->where(fn ($q) => $q
                ->whereDate('licence_expires_at', '<=', $limit)
                ->orWhereDate('insurance_expires_at', '<=', $limit)
                ->orWhereDate('inspection_expires_at', '<=', $limit))->count() : null,
            'drivers'  => $user->can('drivers.view') ? Driver::query()->where('is_active', true)->whereDate('license_expires_at', '<=', $limit)->count() : null,
        ];
    }
}
