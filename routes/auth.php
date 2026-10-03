<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Sign-in Routes (office + client portal)
//  Location: routes/auth.php
//
//  There is deliberately NO public registration: companies are
//  created by the Super Admin, office users by their company admin,
//  and client users by the company (Scope §4, §5, §7).
//
//    login / logout
//    forgot-password → reset-password/{token}
//    activate/{token}  → first password for a new office account
//    confirm-password  → re-enter the password before a sensitive action
//
//  Every form a guest can repeat is rate limited, each with its own
//  counter (throttle:tries,minutes,NAME) so many people on one office
//  network do not use up each other's allowance.
//  Drivers sign in inside the Driver App (routes/driver.php).
// ══════════════════════════════════════════════════════════════════

Route::middleware('guest:web,client')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:60,1,login');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1,password-email')->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::get('activate/{token}', [NewPasswordController::class, 'create'])->name('activation.show');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:10,1,password-reset')->name('password.store');
});

Route::middleware('auth:web')->group(function () {
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
