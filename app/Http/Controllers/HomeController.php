<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  El Tara — HomeController ( / )
//  Location: app/Http/Controllers/HomeController.php
//
//  The front door: sends each person to their own portal.
//    Super Admin → /admin     office staff → /office
//    client user → /client    driver       → /driver
//    nobody      → /login
//  The installed app (PWA) also opens here, so a driver who installed
//  it lands straight in the Driver App.
// ══════════════════════════════════════════════════════════════════

class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($user = $request->user('web')) {
            return redirect()->route($user->isSuperAdmin() ? 'admin.dashboard' : 'office.home');
        }

        if ($request->user('client')) {
            return redirect()->route('client.home');
        }

        if ($request->user('driver')) {
            return redirect()->route('driver.app');
        }

        return redirect()->route('login');
    }
}
