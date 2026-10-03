{{-- ═══════════════════════════════════════════════════════════════
     El Tara — Root page for the office, admin and client portals
     Location: resources/views/app.blade.php

     Every Inertia (Vue) screen renders inside this page.
     · lang / dir come from the language, so Arabic is right-to-left
       from the very first paint (no flash of left-to-right).
     · The "light" class comes from the person's saved theme (or the
       company default), so there is no flash of the wrong theme.
     · Fonts (Tajawal + Inter) are bundled with the app — no Google
       Fonts call, so they also work offline and on slow networks.
     · The manifest makes El Tara installable on a phone (PWA).
     · @routes gives Vue Laravel's route names (Ziggy: route()).
     ═══════════════════════════════════════════════════════════════ --}}
@php
    $locale = app()->getLocale();
    $account = request()->is('client', 'client/*') ? auth('client')->user() : (auth('web')->user() ?? auth('client')->user());
    $theme = $account?->preferredTheme() ?? 'dark';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" class="{{ $theme === 'light' ? 'light' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="{{ $theme === 'light' ? '#EFF6FC' : '#0C1829' }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">

        <title inertia>{{ config('app.name', 'El Tara') }}</title>

        <link rel="icon" type="image/png" href="/images/logo.png">
        <link rel="apple-touch-icon" href="/images/icons/apple-touch-icon.png">
        <link rel="manifest" href="/build/manifest.webmanifest">

        @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
