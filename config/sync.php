<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Offline sync handlers
//  Location: config/sync.php
//
//  Every entry the Driver App saves while offline has a TYPE, e.g.
//  "driver.preferences" or (from Step 3/4) "trip.expense",
//  "trip.collection", "trip.delivery". When the phone uploads, the
//  server looks the type up here and hands the entry to its handler.
//
//  A handler is a class implementing App\Sync\SyncHandler. Adding a
//  new kind of offline entry = write the handler + add one line
//  here. The upload, ordering and duplicate protection are shared
//  (App\Sync\SyncProcessor) and never need to change.
//
//  An entry whose type is not listed is refused (never guessed).
// ══════════════════════════════════════════════════════════════════

return [
    'handlers' => [
        'driver.preferences'  => App\Sync\Handlers\DriverPreferencesHandler::class,

        // Step 4 — the trip work done on the phone.
        'trip.accept'         => App\Sync\Handlers\AcceptTripHandler::class,
        'trip.custody_receive' => App\Sync\Handlers\CustodyReceiveHandler::class,
        'trip.custody_request' => App\Sync\Handlers\CustodyRequestHandler::class,
        'trip.loading'        => App\Sync\Handlers\StartLoadingHandler::class,
        'trip.depart'         => App\Sync\Handlers\DepartHandler::class,
        'trip.delivery'       => App\Sync\Handlers\DeliveryHandler::class,
        'trip.expense'        => App\Sync\Handlers\ExpenseHandler::class,
        'trip.collection'     => App\Sync\Handlers\RecordCollectionHandler::class,
        'trip.transfer'       => App\Sync\Handlers\TransferRequestHandler::class,
        'collection.confirm'  => App\Sync\Handlers\ConfirmCollectionHandler::class,
        'collection.dispute'  => App\Sync\Handlers\DisputeCollectionHandler::class,
    ],
];
