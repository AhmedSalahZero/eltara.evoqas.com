<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Common Strings (English)
//  Location: lang/en/common.php
//
//  The short success messages the server sends after an action
//  (->with('success', __('common.saved'))). Screen labels live in the
//  frontend (resources/js/lang/en.js). Keep in sync with lang/ar.
// ══════════════════════════════════════════════════════════════════

return [
    'saved' => 'Changes saved.',

    // ── Companies (Super Admin) ────────────────────────────────────
    'company_created'     => 'Company created. An activation email was sent to its admin.',
    'company_updated'     => 'Company updated.',
    'activation_resent'   => 'A new activation email was sent to :email.',

    // ── Users (company admin) ──────────────────────────────────────
    'user_invited'        => 'User added. An activation email was sent to :email.',
    'user_updated'        => 'User updated.',
    'user_suspended'      => ':name is suspended and can no longer sign in.',
    'user_reactivated'    => ':name can sign in again.',
    'permissions_saved'   => 'Permissions saved for :name.',
    'reset_link_sent_to'  => 'A password reset link was sent to :email.',

    // ── Step 2 ───────────────────────────────────────────────────
    'vehicle_saved'       => 'Truck :plate saved.',
    'driver_saved'        => 'Driver :name saved.',
    'deleted'             => 'Deleted.',
];
