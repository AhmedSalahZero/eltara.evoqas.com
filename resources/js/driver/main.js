// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App entry point (PWA)
//  Location: resources/js/driver/main.js
//
//  A separate small Vue app for drivers' phones (Scope §8), loaded by
//  the empty shell page resources/views/driver.blade.php:
//    · boot() reads the driver's saved data from the phone first, so
//      the app opens instantly — with or without signal;
//    · the router shows the screens under /driver/…;
//    · the service worker is registered so the app can open offline
//      and be installed on the home screen.
// ══════════════════════════════════════════════════════════════════

import '../../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { boot } from './store';
import { registerServiceWorker } from '@/pwa/register';

boot().then(() => {
    createApp(App).use(router).mount('#driver-app');
});

registerServiceWorker();
