<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Permission Matrix
//  Location: config/permissions.php
//
//  Scope §5: permissions are granted PER NAMED USER, not per role.
//  For each feature the company admin ticks any of the actions that
//  apply to it. A permission key is "feature.action", for example
//  "trips.create" or "wallet_transfers.approve".
//
//  HOW IT IS USED
//  ──────────────
//  · The Users & Permissions screen draws its checkbox grid from the
//    'features' list below (only the actions a feature has are shown).
//  · A user's ticked keys are saved in users.permissions (JSON).
//  · The company admin always holds every key and cannot be limited.
//  · Checking, anywhere:
//        PHP route   → ->middleware('can:trips.create')
//        PHP code    → $user->can('trips.create')
//        Vue screen  → usePermissions().can('trips.create')
//  · A key that is not listed here is ALWAYS refused — a typo shows
//    up as "not allowed", never as an accidental "allowed".
//
//  ADDING A PERMISSION LATER
//  ─────────────────────────
//  Add the action to the feature below. That's all: no migration.
//  (A new action is off for everyone until the admin ticks it.)
// ══════════════════════════════════════════════════════════════════

return [

    // The action names, with their labels on the grid.
    'actions' => [
        'view'       => ['en' => 'View',       'ar' => 'عرض'],
        'create'     => ['en' => 'Create',     'ar' => 'إضافة'],
        'edit'       => ['en' => 'Edit',       'ar' => 'تعديل'],
        'approve'    => ['en' => 'Approve',    'ar' => 'موافقة'],
        'delete'     => ['en' => 'Delete',     'ar' => 'حذف'],
        // Special actions a few features need (Scope §6.3, §6.13).
        'edit_price' => ['en' => 'Edit price', 'ar' => 'تعديل السعر'],
        'reopen'     => ['en' => 'Re-open',    'ar' => 'إعادة فتح'],
        'see_profit' => ['en' => 'See profit', 'ar' => 'عرض الربح'],
        'edit_policy' => ['en' => 'Edit transfer rules', 'ar' => 'تعديل قواعد التحويل'],
    ],

    // The features on the matrix, in the order of the office menu.
    'features' => [
        'dashboard'        => ['en' => 'Dashboard',              'ar' => 'لوحة التحكم',             'actions' => ['view']],
        'client_requests'  => ['en' => 'Client requests',        'ar' => 'طلبات العملاء',           'actions' => ['view', 'edit', 'approve']],
        'trips'            => ['en' => 'Trips',                  'ar' => 'الرحلات',                 'actions' => ['view', 'create', 'edit', 'edit_price', 'see_profit', 'edit_policy', 'delete']],
        'trip_expenses'    => ['en' => 'Trip expenses',          'ar' => 'مصروفات الرحلات',         'actions' => ['view', 'create', 'edit', 'approve', 'delete']],
        'trip_settlement'  => ['en' => 'Trip settlement',        'ar' => 'تسوية الرحلات',           'actions' => ['view', 'approve']],
        'wallet_transfers' => ['en' => 'Wallet transfers',       'ar' => 'تحويلات المحافظ',         'actions' => ['view', 'create', 'approve']],
        'vehicles'         => ['en' => 'Vehicles',               'ar' => 'المركبات',                'actions' => ['view', 'create', 'edit', 'delete']],
        'drivers'          => ['en' => 'Drivers',                'ar' => 'السائقون',                'actions' => ['view', 'create', 'edit', 'delete']],
        'customers'        => ['en' => 'Customers & rate cards', 'ar' => 'العملاء وقوائم الأسعار',  'actions' => ['view', 'create', 'edit', 'delete']],
        'fuel'             => ['en' => 'Fuel',                   'ar' => 'الوقود',                  'actions' => ['view', 'create', 'edit', 'delete']],
        'driver_advances'  => ['en' => 'Driver advances',        'ar' => 'سلف السائقين',            'actions' => ['view', 'create', 'edit', 'approve', 'delete']],
        'invoice_links'    => ['en' => 'Invoice numbers',        'ar' => 'ربط أرقام الفواتير',      'actions' => ['view', 'create', 'edit', 'delete']],
        'month_close'      => ['en' => 'Month close',            'ar' => 'إقفال الشهر',             'actions' => ['view', 'create', 'approve', 'reopen']],
        'reports'          => ['en' => 'Reports',                'ar' => 'التقارير',                'actions' => ['view']],
        'users'            => ['en' => 'Users & permissions',    'ar' => 'المستخدمون والصلاحيات',   'actions' => ['view', 'create', 'edit', 'delete']],
        'settings'         => ['en' => 'Company settings',       'ar' => 'إعدادات الشركة',          'actions' => ['view', 'edit']],
    ],

    // Needs an approval limit on the user (Scope §5 "approval limit").
    'needs_approval_limit' => ['wallet_transfers.approve'],

    // Platform keys — held only by the Super Admin. Not on the matrix.
    'platform' => [
        'platform.companies',
    ],
];
