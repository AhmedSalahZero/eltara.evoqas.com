<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver\AppController ( /driver and every /driver/… page )
//  Location: app/Http/Controllers/Driver/AppController.php
//
//  Returns the Driver App's EMPTY SHELL: the page that loads the app
//  (resources/views/driver.blade.php). It contains no names, no
//  trips, no money — the same page for everybody — which is exactly
//  why the phone may safely keep a copy of it and open the app with
//  no signal. The driver's own data is fetched separately
//  (/driver/api/me) and kept in the phone's private storage.
// ══════════════════════════════════════════════════════════════════

class AppController extends Controller
{
    public function __invoke(): View
    {
        return view('driver');
    }
}
