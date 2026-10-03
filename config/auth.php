<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Authentication (three separate doors)
//  Location: config/auth.php
//
//  Three kinds of account, each with its own guard (session) and its
//  own table, so the three portals can never be confused:
//
//    web    → office staff + Super Admin   (users)        email + password
//    client → client-portal users          (client_users) email + password
//    driver → drivers, Driver App (PWA)    (drivers)      mobile + 4-digit PIN
//
//  Drivers and client users are read with 'eloquent_unscoped'
//  (App\Auth\UnscopedEloquentUserProvider): signing someone in must
//  look up their account directly, not through the company filter.
//
//  Password links:
//    users   → reset links for office staff (60 minutes)
//    invites → activation links sent when an office account is
//              created (7 days, same table as users)
//    clients → reset / activation links for client users
// ══════════════════════════════════════════════════════════════════

return [

    'defaults' => [
        'guard'     => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
        'client' => [
            'driver'   => 'session',
            'provider' => 'client_users',
        ],
        'driver' => [
            'driver'   => 'session',
            'provider' => 'drivers',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],
        'client_users' => [
            'driver' => 'eloquent_unscoped',
            'model'  => App\Models\ClientUser::class,
        ],
        'drivers' => [
            'driver' => 'eloquent_unscoped',
            'model'  => App\Models\Driver::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_reset_tokens',
            'expire'   => 60,
            'throttle' => 60,
        ],
        'invites' => [
            'provider' => 'users',
            'table'    => 'password_reset_tokens',
            'expire'   => 60 * 24 * 7,
            'throttle' => 0,
        ],
        'clients' => [
            'provider' => 'client_users',
            'table'    => 'client_password_reset_tokens',
            'expire'   => 60,
            'throttle' => 60,
        ],
        // Activation links for new client-portal users (Step 2): 7 days.
        'client_invites' => [
            'provider' => 'client_users',
            'table'    => 'client_password_reset_tokens',
            'expire'   => 60 * 24 * 7,
            'throttle' => 0,
        ],
    ],

    // Re-enter the password before sensitive actions: 3 hours.
    'password_timeout' => 10800,
];
