<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Sign-in Strings (English)
//  Location: lang/en/auth.php
//
//  What the server says on the sign-in, reset and activation screens
//  and in the Driver App's sign-in. Keep key-for-key in sync with
//  lang/ar/auth.php (tests/Unit/TranslationsTest checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'failed'        => 'The email / mobile or password is not correct.',
    'failed_pin'    => 'The mobile number or PIN is not correct.',
    'password'      => 'The password is not correct.',
    'throttle'      => 'Too many attempts. Please wait :minutes minute(s) and try again.',
    'not_activated' => 'This account is not activated yet. Open the activation email we sent you and choose your password.',
    'signed_out'    => 'You are signed out. Please sign in again.',

    'login_field' => 'email or mobile',
    'mobile'      => 'mobile number',
    'pin'         => 'PIN',

    'reset_link_sent'     => 'If this email has an El Tara account, a reset link is on its way. Check your inbox.',
    'password_reset_done' => 'Your password was changed. Sign in with the new one.',
    'activation_done'     => 'Your account is active. Sign in with the password you just chose.',
];
