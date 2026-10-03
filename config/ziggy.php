<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Ziggy (the route() helper the Vue screens use)
//  Location: config/ziggy.php
//
//  Ziggy sends the browser a list of route names and addresses so a
//  screen can write route('office.trips.index'). Everything on the
//  list is visible to ANYONE who opens the sign-in page, so the list
//  holds only what the office / client / sign-in screens really use.
//
//  Left out (no screen of the office, client or sign-in pages ever
//  asks for them by name):
//    driver.*       the Driver App is its own program with its own
//                   fixed addresses (/driver/api/...)
//    pwa.*          service worker and manifest, fetched by the browser
//    csp.report     the browser's security-report address
//    activation.*   account-activation links (they come by email)
//    password.reset the reset link (comes by email)
//
//  If a NEW screen needs one of these by name, remove it from here.
// ══════════════════════════════════════════════════════════════════

return [
    'except' => ['driver.*', 'pwa.*', 'csp.report', 'activation.*', 'password.reset'],
];
