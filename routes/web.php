<?php

use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Client\CashController as ClientCashController;
use App\Http\Controllers\Client\FeedbackController as ClientFeedbackController;
use App\Http\Controllers\Client\HomeController as ClientHomeController;
use App\Http\Controllers\Client\PriceController as ClientPriceController;
use App\Http\Controllers\Client\RequestController as ClientRequestController;
use App\Http\Controllers\Client\ShipmentController as ClientShipmentController;
use App\Http\Controllers\Client\StatementController as ClientStatementController;
use App\Http\Controllers\Client\TeamController as ClientTeamController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Office\AdvanceController as OfficeAdvanceController;
use App\Http\Controllers\Office\ClientRequestController as OfficeClientRequestController;
use App\Http\Controllers\Office\FuelController as OfficeFuelController;
use App\Http\Controllers\Office\InvoiceController as OfficeInvoiceController;
use App\Http\Controllers\Office\MonthCloseController as OfficeMonthCloseController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Office\AuditController as OfficeAuditController;
use App\Http\Controllers\Office\DashboardController as OfficeDashboardController;
use App\Http\Controllers\Office\HomeController as OfficeHomeController;
use App\Http\Controllers\Office\ReportController as OfficeReportController;
use App\Http\Controllers\Office\LookupListController as OfficeLookupListController;
use App\Http\Controllers\Office\CustomerController as OfficeCustomerController;
use App\Http\Controllers\Office\DriverController as OfficeDriverController;
use App\Http\Controllers\Office\RouteController as OfficeRouteController;
use App\Http\Controllers\Office\SettingsController as OfficeSettingsController;
use App\Http\Controllers\Office\TripActionController as OfficeTripActionController;
use App\Http\Controllers\Office\TripController as OfficeTripController;
use App\Http\Controllers\Office\TripMoneyController as OfficeTripMoneyController;
use App\Http\Controllers\Office\WalletController as OfficeWalletController;
use App\Http\Controllers\Office\UserController as OfficeUserController;
use App\Http\Controllers\Office\VehicleController as OfficeVehicleController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Web Routes (the address map)
//  Location: routes/web.php
//
//    /                → sends each person to their portal
//    /admin/*         → Platform (Super Admin)          name: admin.*
//    /office/*        → Company Office                  name: office.*
//                         Step 2: vehicles, drivers, customers (+ rate
//                         cards, client users), routes, settings
//                         Step 3: trips (+ expenses, cash, transfers,
//                         settlement), wallets & settlement
//                         Step 6: fuel log, driver advances, invoice
//                         numbers, month close & true profit
//    /client/*        → Client Portal                   name: client.*
//    /driver, /driver/api/* → Driver App (PWA)          routes/driver.php
//    /login, /forgot-password, /activate/* …            routes/auth.php
//    /sw.js           → the offline helper for the Driver App
//    /manifest.webmanifest → the app manifest (also listed by the helper)
//
//  Every change (POST / PATCH) in the office and client portals also
//  passes 'read-only' (blocked when the subscription has ended) and
//  'no-duplicate' (the same form twice in a few seconds is ignored).
//  Office screens add `can:<feature.action>` (config/permissions.php).
// ══════════════════════════════════════════════════════════════════

Route::get('/', HomeController::class)->name('home');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');
Route::post('/csp-report', \App\Http\Controllers\CspReportController::class)->middleware('throttle:60,1')->name('csp.report');
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

// Language / theme — for signed-in people and guests alike.
Route::post('/preferences', [PreferenceController::class, 'update'])
    ->middleware('throttle:30,1,preferences')->name('preferences.update');

require __DIR__.'/auth.php';
require __DIR__.'/driver.php';

// ── Platform (Super Admin) ──────────────────────────────────────
Route::prefix('admin')->name('admin.')->middleware(['auth:web', 'super.admin', 'no-duplicate'])->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::get('companies', [AdminCompanyController::class, 'index'])->name('companies.index');
    Route::post('companies', [AdminCompanyController::class, 'store'])->name('companies.store');
    Route::patch('companies/{company}', [AdminCompanyController::class, 'update'])->name('companies.update');
    Route::post('companies/{company}/activation', [AdminCompanyController::class, 'resendActivation'])
        ->middleware('throttle:6,1,resend-activation')->name('companies.activation');
});

// Profile for office staff and the Super Admin.
Route::middleware(['auth:web'])->group(function () {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->middleware(['no-duplicate', 'throttle:10,1,profile-update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1,profile-password')->name('profile.password');
});

// ── Company Office ──────────────────────────────────────────────
Route::prefix('office')->name('office.')->middleware(['auth:web', 'office', 'read-only', 'no-duplicate'])->group(function () {
    Route::get('/', OfficeHomeController::class)->name('home');
    // ── Step 7: audit log — company admin only (checked in the controller, Scope §12)
    Route::get('audit', [OfficeAuditController::class, 'index'])->name('audit.index');
    Route::get('audit/export', [OfficeAuditController::class, 'export'])->name('audit.export');

    // ── Step 7: reports (Scope §6.14) ───────────────────────────
    Route::get('reports', [OfficeReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');
    Route::get('reports/{report}', [OfficeReportController::class, 'show'])->middleware('can:reports.view')->name('reports.show');
    Route::get('reports/{report}/export', [OfficeReportController::class, 'export'])->middleware('can:reports.view')->name('reports.export');
    Route::get('reports/{report}/print', [OfficeReportController::class, 'print'])->middleware('can:reports.view')->name('reports.print');
    Route::get('dashboard/print', [OfficeDashboardController::class, 'print'])->middleware('can:dashboard.view')->name('dashboard.print');

    Route::get('users', [OfficeUserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::post('users', [OfficeUserController::class, 'store'])->middleware('can:users.create')->name('users.store');
    Route::patch('users/{user}', [OfficeUserController::class, 'update'])->middleware('can:users.edit')->name('users.update');
    Route::put('users/{user}/permissions', [OfficeUserController::class, 'permissions'])->middleware('can:users.edit')->name('users.permissions');
    Route::post('users/{user}/toggle', [OfficeUserController::class, 'toggle'])->middleware('can:users.edit')->name('users.toggle');
    Route::post('users/{user}/link', [OfficeUserController::class, 'sendLink'])
        ->middleware(['can:users.edit', 'throttle:6,1,user-link'])->name('users.link');

    // ── Step 2: master data ─────────────────────────────────────
    Route::get('vehicles', [OfficeVehicleController::class, 'index'])->middleware('can:vehicles.view')->name('vehicles.index');
    Route::post('vehicles', [OfficeVehicleController::class, 'store'])->middleware('can:vehicles.create')->name('vehicles.store');
    Route::get('vehicles/{vehicle}', [OfficeVehicleController::class, 'show'])->middleware('can:vehicles.view')->name('vehicles.show');
    Route::patch('vehicles/{vehicle}', [OfficeVehicleController::class, 'update'])->middleware('can:vehicles.edit')->name('vehicles.update');
    Route::delete('vehicles/{vehicle}', [OfficeVehicleController::class, 'destroy'])->middleware('can:vehicles.delete')->name('vehicles.destroy');

    Route::get('drivers', [OfficeDriverController::class, 'index'])->middleware('can:drivers.view')->name('drivers.index');
    Route::post('drivers', [OfficeDriverController::class, 'store'])->middleware('can:drivers.create')->name('drivers.store');
    Route::get('drivers/{driver}', [OfficeDriverController::class, 'show'])->middleware('can:drivers.view')->name('drivers.show');
    Route::patch('drivers/{driver}', [OfficeDriverController::class, 'update'])->middleware('can:drivers.edit')->name('drivers.update');
    Route::post('drivers/{driver}/toggle', [OfficeDriverController::class, 'toggle'])->middleware('can:drivers.edit')->name('drivers.toggle');
    Route::post('drivers/{driver}/pin', [OfficeDriverController::class, 'resetPin'])->middleware(['can:drivers.edit', 'throttle:10,1,driver-pin'])->name('drivers.pin');
    Route::delete('drivers/{driver}', [OfficeDriverController::class, 'destroy'])->middleware('can:drivers.delete')->name('drivers.destroy');

    Route::get('customers', [OfficeCustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.index');
    Route::post('customers', [OfficeCustomerController::class, 'store'])->middleware('can:customers.create')->name('customers.store');
    Route::patch('customers/{customer}', [OfficeCustomerController::class, 'update'])->middleware('can:customers.edit')->name('customers.update');
    Route::delete('customers/{customer}', [OfficeCustomerController::class, 'destroy'])->middleware('can:customers.delete')->name('customers.destroy');
    Route::post('customers/{customer}/rates', [OfficeCustomerController::class, 'storeRate'])->middleware('can:customers.edit')->name('customers.rates.store');
    Route::patch('customers/{customer}/rates/{rate}', [OfficeCustomerController::class, 'updateRate'])->middleware('can:customers.edit')->name('customers.rates.update');
    Route::delete('customers/{customer}/rates/{rate}', [OfficeCustomerController::class, 'destroyRate'])->middleware('can:customers.edit')->name('customers.rates.destroy');
    Route::post('customers/{customer}/clients', [OfficeCustomerController::class, 'storeClient'])->middleware('can:customers.edit')->name('customers.clients.store');
    Route::post('customers/{customer}/clients/{client}/toggle', [OfficeCustomerController::class, 'toggleClient'])->middleware('can:customers.edit')->name('customers.clients.toggle');
    Route::post('customers/{customer}/clients/{client}/link', [OfficeCustomerController::class, 'resendClient'])
        ->middleware(['can:customers.edit', 'throttle:6,1,client-link'])->name('customers.clients.link');

    // Routes follow the "Customers & rate cards" permission (docs/STEP_02_MASTER_DATA.md).
    Route::get('routes', [OfficeRouteController::class, 'index'])->middleware('can:customers.view')->name('routes.index');
    Route::post('routes', [OfficeRouteController::class, 'store'])->middleware('can:customers.create')->name('routes.store');
    Route::patch('routes/{tripRoute}', [OfficeRouteController::class, 'update'])->middleware('can:customers.edit')->name('routes.update');
    Route::delete('routes/{tripRoute}', [OfficeRouteController::class, 'destroy'])->middleware('can:customers.delete')->name('routes.destroy');

    // ── Step 3: trips, expenses, wallets, transfers, settlement ──
    Route::get('trips', [OfficeTripController::class, 'index'])->middleware('can:trips.view')->name('trips.index');
    Route::post('trips', [OfficeTripController::class, 'store'])->middleware('can:trips.create')->name('trips.store');
    Route::get('trips/export', [OfficeTripController::class, 'export'])->middleware('can:trips.view')->name('trips.export');
    Route::get('trips/{trip}', [OfficeTripController::class, 'show'])->middleware('can:trips.view')->name('trips.show');
    Route::patch('trips/{trip}', [OfficeTripController::class, 'update'])->middleware('can:trips.edit')->name('trips.update');
    Route::get('trips/{trip}/print', [OfficeTripController::class, 'print'])->middleware('can:trips.view')->name('trips.print');
    Route::get('trips/{trip}/pod', [OfficeTripController::class, 'pod'])->middleware('can:trips.view')->name('trips.pod');

    Route::post('trips/{trip}/step', [OfficeTripActionController::class, 'step'])->middleware('can:trips.edit')->name('trips.step');
    Route::post('trips/{trip}/deliver', [OfficeTripActionController::class, 'deliver'])->middleware('can:trips.edit')->name('trips.deliver');
    Route::post('trips/{trip}/custody', [OfficeTripActionController::class, 'custody'])->middleware('can:wallet_transfers.create')->name('trips.custody');
    Route::post('trips/{trip}/cancel', [OfficeTripActionController::class, 'cancel'])->middleware('can:trips.delete')->name('trips.cancel');
    Route::put('trips/{trip}/policy', [OfficeTripActionController::class, 'policy'])->middleware('can:trips.edit_policy')->name('trips.policy');
    Route::post('trips/{trip}/charges', [OfficeTripActionController::class, 'storeCharge'])->middleware('can:trips.edit')->name('trips.charges.store');

    Route::post('trips/{trip}/expenses', [OfficeTripMoneyController::class, 'storeExpense'])->middleware('can:trip_expenses.create')->name('trips.expenses.store');
    Route::post('trips/{trip}/collections', [OfficeTripMoneyController::class, 'storeCollection'])->middleware('can:wallet_transfers.create')->name('trips.collections.store');
    Route::post('trips/{trip}/transfers', [OfficeTripMoneyController::class, 'storeTransfer'])->middleware('can:wallet_transfers.create')->name('trips.transfers.store');
    Route::post('trips/{trip}/settle', [OfficeTripMoneyController::class, 'settle'])->middleware('can:trip_settlement.approve')->name('trips.settle');

    // Records that belong to one trip: the address must match (expense 7 of trip 3).
    Route::scopeBindings()->group(function () {
        Route::delete('trips/{trip}/charges/{charge}', [OfficeTripActionController::class, 'destroyCharge'])->middleware('can:trips.edit')->name('trips.charges.destroy');
        // POST (not PATCH): a form with a photo can only be sent as POST.
        Route::post('trips/{trip}/expenses/{expense}', [OfficeTripMoneyController::class, 'updateExpense'])->middleware('can:trip_expenses.edit')->name('trips.expenses.update');
        Route::delete('trips/{trip}/expenses/{expense}', [OfficeTripMoneyController::class, 'destroyExpense'])->middleware('can:trip_expenses.delete')->name('trips.expenses.destroy');
        Route::get('trips/{trip}/expenses/{expense}/receipt', [OfficeTripController::class, 'receipt'])->middleware('can:trips.view')->name('trips.expenses.receipt');
        Route::post('trips/{trip}/collections/{collection}/resolve', [OfficeTripMoneyController::class, 'resolveCollection'])->middleware('can:wallet_transfers.approve')->name('trips.collections.resolve');
        Route::get('trips/{trip}/collections/{collection}/receipt', [OfficeTripController::class, 'collectionReceipt'])->middleware('can:trips.view')->name('trips.collections.receipt');
    });

    Route::get('wallets', [OfficeWalletController::class, 'index'])->middleware('can:wallet_transfers.view')->name('wallets.index');
    Route::post('transfers/{transfer}/approve', [OfficeTripMoneyController::class, 'approveTransfer'])->middleware('can:wallet_transfers.approve')->name('transfers.approve');
    Route::post('transfers/{transfer}/reject', [OfficeTripMoneyController::class, 'rejectTransfer'])->middleware('can:wallet_transfers.approve')->name('transfers.reject');
    Route::post('transfers/{transfer}/review', [OfficeTripMoneyController::class, 'reviewTransfer'])->middleware('can:wallet_transfers.approve')->name('transfers.review');

    Route::get('settings', [OfficeSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.show');
    Route::put('settings', [OfficeSettingsController::class, 'update'])->middleware('can:settings.edit')->name('settings.update');
    Route::post('settings/categories', [OfficeSettingsController::class, 'storeCategory'])->middleware('can:settings.edit')->name('settings.categories.store');
    Route::patch('settings/categories/{category}', [OfficeSettingsController::class, 'updateCategory'])->middleware('can:settings.edit')->name('settings.categories.update');

    // Truck types and cargo (goods): add from the forms, rename / hide from Company settings
    Route::post('vehicle-types', [OfficeLookupListController::class, 'store'])->defaults('kind', 'vehicle')->middleware('can:vehicles.create')->name('vehicle_types.store');
    Route::patch('vehicle-types/{id}', [OfficeLookupListController::class, 'update'])->defaults('kind', 'vehicle')->middleware('can:settings.edit')->name('vehicle_types.update');
    Route::post('cargo-types', [OfficeLookupListController::class, 'store'])->defaults('kind', 'cargo')->middleware('can:trips.create')->name('cargo_types.store');
    Route::patch('cargo-types/{id}', [OfficeLookupListController::class, 'update'])->defaults('kind', 'cargo')->middleware('can:settings.edit')->name('cargo_types.update');

    // Client requests, complaints and ratings (Step 5)
    Route::get('client-requests', [OfficeClientRequestController::class, 'index'])->middleware('can:client_requests.view')->name('client-requests.index');
    Route::post('client-requests/{clientRequest}/approve', [OfficeClientRequestController::class, 'approve'])->middleware('can:client_requests.approve')->name('client-requests.approve');
    Route::post('client-requests/{clientRequest}/assign', [OfficeClientRequestController::class, 'assign'])->middleware('can:client_requests.approve')->name('client-requests.assign');
    Route::post('client-requests/{clientRequest}/decline', [OfficeClientRequestController::class, 'decline'])->middleware('can:client_requests.approve')->name('client-requests.decline');
    Route::post('complaints/{complaint}/reply', [OfficeClientRequestController::class, 'reply'])->middleware('can:client_requests.edit')->name('complaints.reply');

    // ── Step 6: fuel, advances, invoice numbers, month close ─────
    Route::get('fuel', [OfficeFuelController::class, 'index'])->middleware('can:fuel.view')->name('fuel.index');
    Route::post('fuel', [OfficeFuelController::class, 'store'])->middleware('can:fuel.create')->name('fuel.store');
    Route::patch('fuel/{entry}', [OfficeFuelController::class, 'update'])->middleware('can:fuel.edit')->name('fuel.update');
    Route::delete('fuel/{entry}', [OfficeFuelController::class, 'destroy'])->middleware('can:fuel.delete')->name('fuel.destroy');

    Route::get('advances', [OfficeAdvanceController::class, 'index'])->middleware('can:driver_advances.view')->name('advances.index');
    Route::get('advances/export', [OfficeAdvanceController::class, 'export'])->middleware('can:driver_advances.view')->name('advances.export');
    Route::post('advances', [OfficeAdvanceController::class, 'store'])->middleware('can:driver_advances.create')->name('advances.store');
    Route::post('advances/payroll', [OfficeAdvanceController::class, 'applyPayroll'])->middleware('can:driver_advances.approve')->name('advances.payroll');
    Route::patch('advances/{advance}', [OfficeAdvanceController::class, 'update'])->middleware('can:driver_advances.edit')->name('advances.update');
    Route::post('advances/{advance}/repay', [OfficeAdvanceController::class, 'repay'])->middleware('can:driver_advances.edit')->name('advances.repay');
    Route::post('advances/{advance}/cancel', [OfficeAdvanceController::class, 'cancel'])->middleware('can:driver_advances.delete')->name('advances.cancel');

    Route::get('invoices', [OfficeInvoiceController::class, 'index'])->middleware('can:invoice_links.view')->name('invoices.index');
    Route::post('invoices', [OfficeInvoiceController::class, 'store'])->middleware('can:invoice_links.create')->name('invoices.store');
    Route::patch('invoices/{invoice}', [OfficeInvoiceController::class, 'update'])->middleware('can:invoice_links.edit')->name('invoices.update');
    Route::delete('invoices/{invoice}', [OfficeInvoiceController::class, 'destroy'])->middleware('can:invoice_links.delete')->name('invoices.destroy');

    Route::get('close', [OfficeMonthCloseController::class, 'index'])->middleware('can:month_close.view')->name('close.index');
    Route::get('close/export', [OfficeMonthCloseController::class, 'export'])->middleware('can:month_close.view')->name('close.export');
    Route::get('close/template', [OfficeMonthCloseController::class, 'template'])->middleware('can:month_close.view')->name('close.template');
    Route::post('close/lines', [OfficeMonthCloseController::class, 'storeLine'])->middleware('can:month_close.create')->name('close.lines.store');
    Route::post('close/import', [OfficeMonthCloseController::class, 'import'])->middleware('can:month_close.create')->name('close.import');
    Route::patch('close/lines/{line}', [OfficeMonthCloseController::class, 'updateLine'])->middleware('can:month_close.create')->name('close.lines.update');
    Route::delete('close/lines/{line}', [OfficeMonthCloseController::class, 'destroyLine'])->middleware('can:month_close.create')->name('close.lines.destroy');
    Route::post('close/run', [OfficeMonthCloseController::class, 'close'])->middleware('can:month_close.approve')->name('close.run');
    Route::post('close/reopen', [OfficeMonthCloseController::class, 'reopen'])->middleware('can:month_close.reopen')->name('close.reopen');

    // The bell
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
});

// ── Client Portal ───────────────────────────────────────────────
Route::prefix('client')->name('client.')->middleware(['client.portal', 'read-only', 'no-duplicate'])->group(function () {
    Route::get('/', ClientHomeController::class)->name('home');

    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->middleware('throttle:10,1,client-profile-update')->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1,client-password')->name('profile.password');

    Route::get('requests', [ClientRequestController::class, 'index'])->name('requests.index');
    Route::get('requests/new', [ClientRequestController::class, 'create'])->name('requests.create');
    Route::post('requests', [ClientRequestController::class, 'store'])->name('requests.store');
    Route::post('requests/{clientRequest}/cancel', [ClientRequestController::class, 'cancel'])->name('requests.cancel');

    Route::get('shipments', [ClientShipmentController::class, 'index'])->name('shipments.index');
    Route::get('shipments/{trip}', [ClientShipmentController::class, 'show'])->name('shipments.show');
    Route::get('shipments/{trip}/pod', [ClientShipmentController::class, 'pod'])->name('shipments.pod');
    Route::post('shipments/{trip}/rating', [ClientShipmentController::class, 'rate'])->name('shipments.rate');

    Route::get('cash', [ClientCashController::class, 'index'])->name('cash.index');
    Route::post('cash', [ClientCashController::class, 'record'])->name('cash.record');
    Route::post('cash/{collection}/confirm', [ClientCashController::class, 'confirm'])->name('cash.confirm');
    Route::post('cash/{collection}/dispute', [ClientCashController::class, 'dispute'])->name('cash.dispute');

    Route::get('statement', [ClientStatementController::class, 'index'])->name('statement.index');
    Route::get('statement/export', [ClientStatementController::class, 'export'])->name('statement.export');
    Route::get('statement/print', [ClientStatementController::class, 'print'])->name('statement.print');

    Route::get('prices', [ClientPriceController::class, 'index'])->name('prices.index');

    Route::get('feedback', [ClientFeedbackController::class, 'index'])->name('feedback.index');
    Route::post('feedback', [ClientFeedbackController::class, 'store'])->name('feedback.store');

    Route::get('team', [ClientTeamController::class, 'index'])->name('team.index');
    Route::post('team', [ClientTeamController::class, 'store'])->name('team.store');
    Route::post('team/{user}/toggle', [ClientTeamController::class, 'toggle'])->name('team.toggle');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
});
