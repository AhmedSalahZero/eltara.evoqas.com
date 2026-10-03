<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Email Strings (English)
//  Location: lang/en/emails.php
//
//  Every key is used by resources/views/emails/*.blade.php or
//  app/Notifications/*.php. A missing key is not a silent failure —
//  the raw key would appear in the delivered email — so keep this
//  file key-for-key in sync with lang/ar/emails.php.
//  Lines with "|" depend on a number (trans_choice).
// ══════════════════════════════════════════════════════════════════

return [

    // ── Account activation ────────────────────────────────────────
    'activate' => [
        'subject'  => 'Welcome to El Tara — activate your account',
        'heading'  => 'Activate your El Tara account',
        'greeting' => 'Hi :name,',
        'intro'    => 'An El Tara account was created for you at :company. Choose your password to start.',
        'button'   => 'Choose my password',
        'expire'   => '{1} This link works for 1 day.|[2,*] This link works for :count days.',
        'fallback' => "If the button doesn't work, copy this link into your browser:",
        'closing'  => 'Every trip counted.',
    ],

    // ── Password reset ────────────────────────────────────────────
    'reset_password' => [
        'subject'  => 'Reset your El Tara password',
        'heading'  => 'Reset your password',
        'greeting' => 'Hi :name,',
        'intro'    => 'We received a request to reset the password of your El Tara account.',
        'button'   => 'Reset password',
        'expire'   => '{1} This link expires in 1 minute.|[2,*] This link expires in :count minutes.',
        'fallback' => "If the button doesn't work, copy this link into your browser:",
        'ignore'   => 'If you did not ask for this, do nothing — your password stays the same.',
        'closing'  => 'Thanks.',
    ],

    // ── Subscription ending ───────────────────────────────────────
    'subscription_ending' => [
        'subject'       => '{0} Your El Tara subscription ends today|{1} Your El Tara subscription ends in 1 day|[2,*] Your El Tara subscription ends in :days days',
        'heading'       => 'Your subscription is ending soon',
        'greeting'      => 'Hi :name,',
        'intro'         => '{0} The El Tara subscription of :company ends today.|{1} The El Tara subscription of :company ends in 1 day.|[2,*] The El Tara subscription of :company ends in :days days.',
        'ends_on_label' => 'Last day of full access',
        'what_happens'  => 'After this date El Tara becomes read-only for your team: everyone can still sign in and see every record, but nothing new can be recorded. Nothing is deleted.',
        'how_to_renew'  => 'To keep working without a break, contact the El Tara team before this date.',
        'closing'       => 'Thank you for working with El Tara.',
    ],

    // ── Shared layout ─────────────────────────────────────────────
    'footer_tagline' => 'Every trip counted.',
];
