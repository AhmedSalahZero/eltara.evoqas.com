<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client requests & Client Portal messages (English)
//  Location: lang/en/client.php
//
//  What the server says after a client-portal or client-request action
//  (ok.*) and why a rule refused one. Used by
//  App\Services\ClientRequestService and the Client\* controllers.
//  Keep key-for-key in sync with lang/ar/client.php.
// ══════════════════════════════════════════════════════════════════

return [
    'ok' => [
        'requested'     => 'Your request was sent. The transport company will answer it soon.',
        'cancelled'     => 'The request was withdrawn.',
        'rated'         => 'Thank you — your rating was saved.',
        'confirmed'     => 'Thank you — you confirmed this amount.',
        'disputed'      => 'Your objection was sent. Management will look into it.',
        'cash_recorded' => 'Saved. The driver will be asked to confirm that he received it.',
        'complained'    => 'Your complaint was sent to the transport company.',
        'approved'      => 'The request was approved. You can assign the trucks now or later.',
        'assigned'      => 'Trucks assigned — :count trip(s) created and the client was told.',
        'declined'      => 'The request was declined and the client was told.',
        'replied'       => 'Your reply was sent to the client.',
    ],

    'no_lines'             => 'Add at least one line: how many trucks and which weight.',
    'too_many_trucks'      => 'One request can ask for up to 50 trucks. Please split it into two requests.',
    'no_price'             => 'There is no agreed price for this route. Please contact the transport company.',
    'loading_in_past'      => 'The loading time must be in the future.',
    'request_decided'      => 'This request has already been answered, so it cannot be changed.',
    'assign_count'         => 'This request needs exactly :count truck(s). Choose a truck for each one.',
    'assign_same_truck'    => 'The same truck cannot be chosen twice for one request.',
    'cash_not_allowed'     => 'Your company is not set up to pay drivers in cash. Please contact the transport company.',
    'rate_after_delivery'  => 'You can rate a trip after it is delivered.',
    'already_rated'        => 'This trip was already rated.',
    'cannot_suspend_admin' => 'The account admin (and yourself) cannot be suspended.',
];
