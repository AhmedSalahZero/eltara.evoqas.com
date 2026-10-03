<?php

// Step 7 — words the server prints itself (the printable dashboard and report pages).
return [
    'actions' => [
        'requests'    => 'New trip requests from clients',
        'collections' => 'Collections not confirmed by both sides',
        'transfers'   => 'Wallet transfers waiting for approval',
        'review'      => 'Auto-approved transfers — to review',
        'unsettled'   => 'Delivered trips not yet settled',
        'loss'        => 'Loss-making trips this month',
        'over'        => 'Trips over route budget',
        'docs'        => 'Documents expiring within 30 days',
        'fuel'        => 'Abnormal fuel consumption',
        'invoices'    => 'Settled trips without an invoice number',
    ],
];
