<?php

use Illuminate\Support\Facades\Schedule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Scheduled Tasks
//  Location: routes/console.php
//
//  NOTE: none of this runs until the server calls Laravel's scheduler
//  once a minute. On the live (Linux) server that is one cron line:
//      * * * * * cd /path/to/el_tara && php artisan schedule:run >> /dev/null 2>&1
//  On your Windows computer you can run it by hand when needed:
//      php artisan schedule:run
//
//  Commands (app/Console/Commands):
//    subscriptions:notify-expiring → 30-day subscription warning email
//    documents:notify-expiring     → bell line when a truck / driver document nears its end (Step 7)
//    drivers:notify-unsynced       → bell line when a driver on the road has not synced (Step 7)
//    sync:prune-receipts           → tidy old offline-sync receipts
//    mail:diagnose                 → test the email setup by hand
// ══════════════════════════════════════════════════════════════════

Schedule::command('subscriptions:notify-expiring')->dailyAt('07:00')->withoutOverlapping();

Schedule::command('sync:prune-receipts')->dailyAt('03:30')->withoutOverlapping();

Schedule::command('documents:notify-expiring')->dailyAt('07:30')->withoutOverlapping();

Schedule::command('drivers:notify-unsynced')->hourly()->withoutOverlapping();
