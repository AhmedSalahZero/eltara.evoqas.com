<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Trips, wallets and settlement messages (English)
//  Location: lang/en/trips.php
//
//  What the server says after a trip action (ok.*) and why a business
//  rule refused one (the other keys) — shown to people as they are,
//  so each says what happened and what to do. Used by
//  App\Services\Trips\* (TripRuleException::because('trips.no_pod')).
//  Keep key-for-key in sync with lang/ar/trips.php.
// ══════════════════════════════════════════════════════════════════

return [
    'status' => [
        'planned' => 'Planned', 'accepted' => 'Accepted', 'loading' => 'Loading', 'on_road' => 'On the road',
        'delivered' => 'Delivered', 'settled' => 'Settled & closed', 'cancelled' => 'Cancelled',
    ],

    'ok' => [
        'created'          => 'Trip :number created.',
        'saved'            => 'Changes saved.',
        'step'             => 'Done — the trip moved to its next step.',
        'custody'          => 'Custody of :amount EGP recorded as handed to the driver.',
        'delivered'        => 'Delivery recorded with its proof. The trip can now be settled.',
        'cancelled'        => 'Trip cancelled.',
        'expense'          => 'Expense saved.',
        'expense_deleted'  => 'Expense deleted. The driver\'s wallets were corrected.',
        'collection'       => 'Cash from the client recorded. The client still has to confirm it.',
        'resolved'         => 'Dispute resolved.',
        'transfer_auto'    => 'Transfer done automatically under this trip\'s policy. It is listed for review.',
        'transfer_pending' => 'Transfer recorded. It is waiting for approval.',
        'approved'         => 'Transfer approved.',
        'rejected'         => 'Transfer rejected.',
        'reviewed'         => 'Marked as reviewed.',
        'settled'          => 'Trip :number settled and closed (net :net EGP).',
    ],

    // ── Rules ────────────────────────────────────────────────────────
    'not_running'             => 'This can only be done once the driver has accepted the trip and before it is closed.',
    'needs_driver'            => 'This trip has no driver. Choose the driver first.',
    'amount_positive'         => 'Enter an amount greater than zero.',
    'transfer_exceeds'        => 'That is more than the collection money the driver holds on this trip (:amount EGP available).',
    'own_request'             => 'You cannot approve or reject a transfer you requested yourself. Another approver has to decide it.',
    'own_collection'          => 'You recorded this cash, so you cannot resolve the dispute about it. Another person has to decide it.',
    'custody_over_cap'        => 'This would put the custody above the limit for this trip (:cap EGP; already issued :issued EGP). Only the company admin can go above it.',
    'above_your_limit'        => 'This amount is above your approval limit (:limit EGP). The company admin has to approve it.',
    'transfer_not_to_review'  => 'This transfer is not waiting for review.',
    'transfer_already_decided'=> 'This transfer has already been decided.',
    'trip_closed'             => 'This trip is closed (settled or cancelled). Nothing on it can change any more.',
    'bad_paid_from'           => 'Choose who paid this expense.',
    'category_required'       => 'Choose the expense category.',
    'personal_from_wallet'    => 'Personal spending can only come out of custody or collection money.',
    'hired_no_custody'        => 'A hired truck gets no custody. Record its costs as paid by the company.',
    'not_disputed'            => 'This amount is not in dispute.',
    'collection_cancelled'    => 'This amount was cancelled when its dispute was resolved.',
    'price_needs_permission'  => 'Changing the agreed price needs the "Trips → Edit price" permission.',
    'no_rate_card'            => 'This customer has no agreed price for this route. Add it to the rate card, or ask someone with "Edit price" to type the price.',
    'no_price'                => 'This customer has no agreed price for this route. Enter the freight price.',
    'bad_policy'              => 'Choose a transfer policy.',
    'busy_with'               => 'The truck or the driver is still on trip :trip. Finish that trip first.',
    'custody_when'            => 'Custody can be handed over from acceptance until delivery.',
    'custody_first'           => 'Hand the custody to the driver before loading starts.',
    'no_pod'                  => 'The photo of the stamped delivery note is required.',
    'cannot_cancel'           => 'Only a trip that has not started loading can be cancelled.',
    'cancel_money_moved'      => 'Money has already moved on this trip. Settle it instead of cancelling it.',
    'vehicle_maintenance'     => 'Truck :plate is in maintenance and cannot be booked.',
    'vehicle_booked'          => 'Truck :plate already has a booked next trip (:trip).',
    'driver_booked'           => ':name already has a booked next trip (:trip).',
    'driver_suspended'        => ':name is suspended and cannot be given trips.',
    'wrong_step'              => 'The trip is not at this step any more. The screen has been refreshed.',
    'already_settled'         => 'This trip is already settled.',
    'not_delivered'           => 'The trip can only be settled after delivery.',
    'settle_unconfirmed'      => 'Some cash is not confirmed yet. Tick "I accept settling with unconfirmed cash" to go on; it is recorded with the settlement.',
    'settle_blocked'          => 'The trip cannot be settled yet — see what is missing on the settlement panel.',

    // ── Words used inside records ────────────────────────────────────
    'personal'              => 'Personal',
    'personal_on_trip'      => 'Personal spending on trip :trip',
    'transfer_for_expense'  => 'Collection money used for: :what',
    'ledger_expense_deleted'=> 'Expense deleted',

    // ── Driver App (Step 4) ────────────────────────────────────
    'driver_trip_missing'   => 'This trip is not assigned to you any more.',
    'driver_cash_missing'   => 'This cash entry was not found.',
    'driver_photo_missing'  => 'The photo did not reach the server. Open the entry and send it again.',
    'receipt_required'      => 'A photo of the receipt is required.',
    'collection_closed'     => 'This cash entry is already disputed or cancelled — management will decide.',
    'driver_custody_not_issued' => 'The office has not handed over the custody yet.',
    'driver_custody_already'=> 'You already signed for all the custody handed over so far.',
    'driver_photo_old'      => 'This photo is too old. Take a new photo of the document with the camera.',
];
