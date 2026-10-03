<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Application settings
//  Location: config/eltara.php
//
//  The platform-wide numbers in one place. Each can be changed in
//  .env without touching code (the name in env('…') is the .env key).
//  Company-specific business rules (transfer policy, custody buffer,
//  diesel price …) are NOT here — each company sets those itself in
//  Company Settings (Step 2).
// ══════════════════════════════════════════════════════════════════

return [

    // The platform owner's first account (created by the seeder).
    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@eltara.app'),
        'name'  => env('SUPER_ADMIN_NAME', 'El Tara Admin'),
    ],

    // Password used by the seeders for demo / first accounts.
    'default_password' => env('DEFAULT_PASSWORD'),

    // New companies (the Super Admin can change each one).
    'company_defaults' => [
        'office_users_limit'    => (int) env('DEFAULT_OFFICE_USERS_LIMIT', 5),
        'driver_accounts_limit' => (int) env('DEFAULT_DRIVER_ACCOUNTS_LIMIT', 15),
        'trial_days'            => (int) env('DEFAULT_TRIAL_DAYS', 30),
    ],

    'subscription' => [
        // Scope §4.2: alert 30 days before the end date.
        'notify_days_before'      => (int) env('SUBSCRIPTION_NOTIFY_DAYS_BEFORE', 30),
        // Minimum days between two reminder emails to the same company.
        'notify_again_after_days' => (int) env('SUBSCRIPTION_NOTIFY_AGAIN_AFTER_DAYS', 7),
        // Who a company contacts to renew (shown on the banner).
        'support_email'           => env('SUPPORT_EMAIL'),
        'support_phone'           => env('SUPPORT_PHONE'),
    ],

    'documents' => [
        // A licence / insurance / inspection turns amber this many days before it ends
        // (vehicle and driver lists, the dashboard, the documents report).
        'alert_days' => (int) env('DOCUMENT_ALERT_DAYS', 30),
    ],

    'driver_app' => [
        // PIN sign-in: tries allowed per mobile number, then a wait.
        'pin_max_attempts'  => 5,
        'pin_lockout_minutes' => 15,
    ],

    // Custody for one trip may not pass the planned custody by more than this percent,
    // unless the company admin does it (and it is then flagged in the audit log).
    'custody_cap_percent' => (int) env('CUSTODY_CAP_PERCENT', 50),

    'uploads' => [
        // Biggest photo the Driver App may send (the phone shrinks it first).
        'max_kb' => 6144,
        // A receipt / cash / delivery-note photo must have been taken this recently (minutes before the
        // driver saved it in the app). Stops an old receipt from the gallery being used again.
        'photo_max_age_minutes' => (int) env('PHOTO_MAX_AGE_MINUTES', 15),
    ],

    'sync' => [
        // Most entries one upload may carry.
        'max_batch'            => 50,
        // Days to keep "already received" receipts before pruning.
        'keep_receipts_days'   => 90,
        // How far back a phone may date an entry; later or earlier times are pulled to this window.
        'max_backdate_days'    => 7,
    ],
];
