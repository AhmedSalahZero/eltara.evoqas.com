<?php

// Step 7 — Audit log screen (Scope §12): how an action code reads. "area.verb" → "<Area> <verb>".
return [
    'template' => ':area :verb',
    'areas' => [
        'auth' => 'Sign-in',
        'advance' => 'Advance', 'client_request' => 'Client request', 'client_user' => 'Client user', 'collection' => 'Collection', 'company' => 'Company',
        'complaint' => 'Complaint', 'custody' => 'Custody', 'customer' => 'Customer', 'driver' => 'Driver', 'expense' => 'Expense', 'expense_category' => 'Expense category',
        'fuel' => 'Fuel refuel', 'ga' => 'G&A line', 'invoice' => 'Invoice link', 'month' => 'Month', 'permissions' => 'Permissions', 'rate_card' => 'Rate card',
        'report' => 'Report', 'route' => 'Route', 'settings' => 'Company settings', 'transfer' => 'Wallet transfer', 'trip' => 'Trip', 'user' => 'User', 'vehicle' => 'Truck',
        'vehicle_type' => 'Truck type', 'cargo_type' => 'Cargo type',
    ],
    'verbs' => [
        'login' => 'successful', 'login_failed' => 'failed', 'login_locked' => 'blocked (too many tries)',
        'created' => 'created', 'updated' => 'updated', 'deleted' => 'deleted', 'cancelled' => 'cancelled', 'accepted' => 'accepted', 'assigned' => 'assigned',
        'declined' => 'declined', 'submitted' => 'submitted', 'invited' => 'invited', 'reactivated' => 'reactivated', 'suspended' => 'suspended', 'recorded' => 'recorded',
        'disputed' => 'disputed', 'answered' => 'answered', 'issued' => 'issued', 'pin_reset' => 'PIN reset', 'imported' => 'imported', 'linked' => 'linked', 'closed' => 'closed',
        'reopened' => 'reopened', 'changed' => 'changed', 'price_changed' => 'price changed', 'exported' => 'exported', 'approved' => 'approved', 'auto_approved' => 'approved automatically',
        'rejected' => 'rejected', 'requested' => 'requested', 'reviewed' => 'reviewed', 'settled' => 'settled', 'charge_added' => 'charge added', 'charge_removed' => 'charge removed',
        'policy_changed' => 'transfer policy changed', 'email_changed' => 'e-mail changed', 'repaid' => 'repaid', 'payroll_applied' => 'applied to payroll', 'loading' => 'loading started',
        'departed' => 'left for the road', 'delivered' => 'delivered', 'confirmed' => 'confirmed', 'resolved' => 'resolved', 'custody' => 'custody issued',
    ],
    'actors' => ['user' => 'Office user', 'driver' => 'Driver', 'client' => 'Client', 'system' => 'System'],
    'subjects' => ['Trip' => 'Trip', 'Vehicle' => 'Truck', 'Driver' => 'Driver', 'Customer' => 'Customer', 'User' => 'User', 'WalletTransfer' => 'Transfer', 'MonthClose' => 'Month close', 'Invoice' => 'Invoice', 'FuelEntry' => 'Refuel', 'DriverAdvance' => 'Advance'],
    'columns' => ['when' => 'When', 'who' => 'Who', 'action' => 'Action', 'subject' => 'On', 'details' => 'Details', 'ip' => 'IP address'],
    'title' => 'Audit log',
];
