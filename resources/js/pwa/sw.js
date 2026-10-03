// ══════════════════════════════════════════════════════════════════
//  El Tara — Service worker (the offline helper on the phone)
//  Location: resources/js/pwa/sw.js
//  Built by `npm run build` into public/build/sw.js, served at /sw.js.
//
//  WHAT IT KEEPS ON THE PHONE
//    1. The app's files (JavaScript, styles, fonts, icons) — the same
//       for everybody, listed automatically at build time.
//    2. The Driver App's EMPTY shell page (/driver), which contains no
//       personal data (resources/views/driver.blade.php). It is kept per
//       build version and only when it fits the files of this worker, so
//       page and files can never be out of step.
//  With these two, the Driver App opens with no signal. The driver's
//  own data is not here — it is in the phone's private database
//  (resources/js/driver/db.js), wiped on sign-out.
//
//  WHAT IT NEVER KEEPS — on purpose
//    · office, admin and client-portal pages: they carry company data
//      inside the page, and a stored copy could be shown to the wrong
//      person (this happened on an earlier project — see
//      App\Http\Middleware\NoStoreForAuthenticated). Those pages
//      always come fresh from the server.
//    · /driver/api/* answers (profile, sync): always live.
//
//  UPDATES — a new version waits until the driver taps "Update"
//  (resources/js/pwa/register.js), so nothing changes mid-entry.
// ══════════════════════════════════════════════════════════════════

import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { CacheFirst } from 'workbox-strategies';
import { ExpirationPlugin } from 'workbox-expiration';
import { clientsClaim } from 'workbox-core';

const SHELL_URL = '/driver';
const SHELL_PREFIX = 'eltara-driver-shell-';

// ── The Driver App page and its files are kept TOGETHER, as one version ──
//  The empty shell page names the app's files by their built (hashed) names. If a page from a NEWER
//  build were saved while the phone still holds the files of an OLDER build, everything would still
//  work online, but with no signal the page would ask for files that are not on the phone and the
//  screen would stay blank. So:
//    · the page is saved in a store named after this build's version, and
//    · it is saved ONLY IF every built file it names is in this worker's own file list.
//  A worker can therefore never keep a page that does not fit its files, and a failure to save the
//  page never stops the files themselves from being saved (the app files are saved either way).
const files = self.__WB_MANIFEST;
const known = new Set(files.map((f) => f.url));
const version = (() => {
    let h = 5381;
    for (const f of files) {
        const text = `${f.url}|${f.revision ?? ''};`;
        for (let i = 0; i < text.length; i++) h = ((h * 33) ^ text.charCodeAt(i)) >>> 0;
    }

    return h.toString(36);
})();
const SHELL_CACHE = `${SHELL_PREFIX}${version}`;

precacheAndRoute(files);
cleanupOutdatedCaches();
clientsClaim();

/** True when every /build/… file named inside the page is one this worker keeps. */
function fitsTheFiles(html) {
    const names = [...html.matchAll(/\/build\/[^"'\s)>]+/g)].map((m) => m[0]);

    return names.length > 0 && names.every((name) => known.has(name));
}

/** Downloads the page and keeps it, if (and only if) it fits this version's files. */
async function keepShell() {
    const response = await fetch(SHELL_URL, { credentials: 'same-origin', cache: 'no-store' });

    if (!response.ok || response.redirected) throw new Error(`The Driver App page answered ${response.status}.`);
    if (!fitsTheFiles(await response.clone().text())) throw new Error('The Driver App page belongs to a different build.');

    await (await caches.open(SHELL_CACHE)).put(SHELL_URL, response);
}

self.addEventListener('install', (event) => {
    // Never let a failed page download stop the installation of the files.
    event.waitUntil(keepShell().catch((error) => console.warn('El Tara: could not keep the app page yet.', error)));
});

self.addEventListener('activate', (event) => {
    // The pages of older versions are no longer needed.
    event.waitUntil(caches.keys().then((names) => Promise.all(
        names.filter((name) => name.startsWith(SHELL_PREFIX) && name !== SHELL_CACHE).map((name) => caches.delete(name)),
    )));
});

// Driver App pages (/driver, /driver/trips, /driver/account …): the kept page. It is the same for
// everybody and holds no data, so it opens at once, with or without signal.
registerRoute(new NavigationRoute(async ({ request }) => {
    const kept = await (await caches.open(SHELL_CACHE)).match(SHELL_URL);
    if (kept) return kept;

    // Not kept yet (the first visit had no signal, say): try now, then fall back to the live page.
    try {
        await keepShell();
        const now = await (await caches.open(SHELL_CACHE)).match(SHELL_URL);
        if (now) return now;
    } catch {
        /* the live page below decides */
    }

    try {
        return await fetch(request);
    } catch {
        return Response.error();
    }
}, { allowlist: [/^\/driver(\/(?!api)[^?]*)?$/] }));

// Public images (logo, icons, sign-in picture) — the same for everyone.
registerRoute(
    ({ url, request }) => url.origin === self.location.origin && request.destination === 'image' && url.pathname.startsWith('/images/'),
    new CacheFirst({ cacheName: 'eltara-images', plugins: [new ExpirationPlugin({ maxEntries: 40, maxAgeSeconds: 60 * 60 * 24 * 30 })] }),
);

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});
