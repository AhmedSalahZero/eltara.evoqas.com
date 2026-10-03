<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;

// ══════════════════════════════════════════════════════════════════
//  El Tara — UnscopedEloquentUserProvider
//  Location: app/Auth/UnscopedEloquentUserProvider.php
//
//  Drivers and client users are company-scoped models: normally every
//  query about them is filtered to the current company. Signing
//  someone in is the one moment that must NOT be filtered — the
//  sign-in happens before we know the company, and a browser that is
//  also signed in to the office (a dispatcher testing the driver app)
//  must not hide other accounts from the lookup.
//
//  This provider is Laravel's normal one with only the company filter
//  removed, and only for finding the account that is signing in or is
//  already signed in. Registered as 'eloquent_unscoped' in
//  AppServiceProvider; used by config/auth.php.
// ══════════════════════════════════════════════════════════════════

class UnscopedEloquentUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope('company');
    }
}
