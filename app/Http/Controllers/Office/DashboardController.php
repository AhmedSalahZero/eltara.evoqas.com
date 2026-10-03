<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\DashboardController ( /office/dashboard/print )
//  Location: app/Http/Controllers/Office/DashboardController.php
//
//  Scope §6.1 "Export": the dashboard as a printable page (Save as
//  PDF from the print window — the same method as the client
//  statement). The screen itself is Office\HomeController.
// ══════════════════════════════════════════════════════════════════

class DashboardController extends Controller
{
    public function print(Request $request, DashboardService $service): View
    {
        $user = $request->user('web')->loadMissing('company');
        $dash = $service->build($user->company_id, DashboardService::period($request->query('period')));

        return view('office.dashboard-print', [
            'company' => $user->company->displayName(),
            'dash'    => $dash,
            'locale'  => app()->getLocale(),
            'by'      => $user->name,
        ]);
    }
}
