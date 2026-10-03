// ══════════════════════════════════════════════════════════════════
//  El Tara — Registers the offline helper (service worker)
//  Location: resources/js/pwa/register.js
//
//  Called by both apps. Only on a built site (npm run build) — during
//  `npm run dev` there is no worker, so screens always reload fresh.
//  When a new version of El Tara is published, `updateReady` becomes
//  true and the Driver App shows an "Update" button; applyUpdate()
//  switches to the new version and reloads.
// ══════════════════════════════════════════════════════════════════

import { ref } from 'vue';
import { Workbox } from 'workbox-window';

export const updateReady = ref(false);
let wb = null;

export function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) return;

    wb = new Workbox('/sw.js', { scope: '/' });
    wb.addEventListener('waiting', () => (updateReady.value = true));
    wb.register().catch((error) => console.warn('El Tara: the offline helper could not start.', error));
}

export function applyUpdate() {
    if (!wb) return;
    wb.addEventListener('controlling', () => window.location.reload());
    wb.messageSkipWaiting();
}
