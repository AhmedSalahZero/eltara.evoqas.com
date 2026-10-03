{{-- ═══════════════════════════════════════════════════════════════
     El Tara — Driver App shell (PWA)
     Location: resources/views/driver.blade.php

     The page every /driver address returns. It is deliberately EMPTY
     of personal data — no name, no trip, no money, no security token
     — and identical for every driver, so the phone can safely keep a
     copy of it and open the app with no signal (resources/js/pwa/sw.js).
     The app itself (resources/js/driver/main.js) then reads the
     driver's data from the phone's private storage and the server.

     Starts in Arabic / dark; the app switches to the driver's own
     language and theme as soon as it starts.
     ═══════════════════════════════════════════════════════════════ --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0C1829">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

        <title>التارة — تطبيق السائق</title>

        <link rel="icon" type="image/png" href="/images/logo.png">
        <link rel="apple-touch-icon" href="/images/icons/apple-touch-icon.png">
        <link rel="manifest" href="/build/manifest.webmanifest">

        @vite(['resources/css/app.css', 'resources/js/driver/main.js'])
    </head>
    <body>
        <div id="driver-app"></div>
    </body>
</html>
