<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Error Strings (English)
//  Location: lang/en/errors.php
//
//  Shown directly to people, so each one says what happened and what
//  to do next. Keep key-for-key in sync with lang/ar/errors.php.
// ══════════════════════════════════════════════════════════════════

return [
    // ── Access ─────────────────────────────────────────────────────
    'account_suspended' => 'This account is suspended. Please contact your company administrator.',
    'account_orphaned'  => 'This account is no longer linked to a company. Please contact El Tara support.',
    'company_suspended' => 'Your company\'s El Tara account is suspended. Please contact El Tara support.',
    'read_only'         => 'Your company\'s subscription has ended, so El Tara is read-only: you can look but not record. Please contact El Tara to renew.',
    'forbidden'         => 'You do not have permission for this.',
    'server'            => 'The server had a problem with this entry. It stays on your phone and will be tried again.',

    // ── Forms ──────────────────────────────────────────────────────
    'duplicate_submission' => 'That looks like the entry you just saved, so it was not recorded twice. If you meant to send it again, wait a moment and retry.',

    // ── Limits ─────────────────────────────────────────────────────
    'office_limit_reached' => 'All :limit office user places are in use. Suspend someone, or ask El Tara to raise your limit.',
    'driver_limit_reached' => 'All :limit driver accounts are in use. Suspend a driver, or ask El Tara to raise your limit.',
    'limit_below_usage'    => 'The limit cannot be lower than the :used accounts already active.',

    // ── Users ──────────────────────────────────────────────────────
    'cannot_change_admin'   => 'The company admin always has every permission and cannot be limited or suspended here.',
    'cannot_suspend_self'   => 'You cannot suspend your own account.',
    'policy_needs_permission' => 'Changing the transfer rules needs the "Edit transfer rules" permission. Ask your company admin.',
    'cannot_edit_own_permissions' => 'You cannot change your own permissions. Ask the company admin.',
    'cannot_grant_unheld' => 'You can only give permissions you hold yourself. Ask the company admin for the rest.',
    'approval_limit_needed' => 'Set an approval limit for a person who can approve wallet transfers.',
    'email_taken'           => 'This email already has an El Tara account.',
    'phone_taken'           => 'This mobile number already belongs to another account.',

    // ── Step 2: master data ──────────────────────────────────────
    'in_use_cannot_delete'  => 'This record is used elsewhere and cannot be deleted. Suspend or hide it instead.',
    'plate_taken'           => 'A truck with this plate already exists in your company.',
    'list_item_exists'      => 'This name already exists in the list.',
    'route_already_priced'  => 'This customer already has a price for this route — edit that line instead.',
    'route_has_prices'      => 'Customers have prices on this route. Remove it from their rate cards first, or hide it.',
];
