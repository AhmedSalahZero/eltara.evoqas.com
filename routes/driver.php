<?php

use App\Http\Controllers\Driver\ApiController;
use App\Http\Controllers\Driver\AppController;
use App\Http\Controllers\Driver\UploadController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App Routes (PWA)
//  Location: routes/driver.php
//
//  /driver, /driver/…  → the app's empty shell (same for everyone,
//                         safe for the phone to keep for offline use)
//  /driver/api/login   → mobile + PIN
//  /driver/api/me      → the driver's own profile
//  /driver/api/sync    → upload the entries saved while offline
//  /driver/api/logout
//
//  The api routes answer JSON and never redirect, because they are
//  called by the app, not opened by a person.
// ══════════════════════════════════════════════════════════════════

Route::prefix('driver/api')->name('driver.api.')->group(function () {
    Route::post('login', [ApiController::class, 'login'])->middleware('throttle:60,1,driver-login')->name('login');
    Route::post('logout', [ApiController::class, 'logout'])->name('logout');

    Route::middleware('driver.app')->group(function () {
        Route::get('me', [ApiController::class, 'me'])->name('me');
        Route::get('snapshot', [ApiController::class, 'snapshot'])->name('snapshot');
        Route::post('uploads', [UploadController::class, 'store'])->middleware(['read-only', 'throttle:driver-upload'])->name('uploads');
        Route::post('sync', [ApiController::class, 'sync'])->middleware(['read-only', 'throttle:driver-sync'])->name('sync');
    });
});

Route::get('driver/{path?}', AppController::class)->where('path', '^(?!api).*$')->name('driver.app');
