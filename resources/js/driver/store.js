// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App: state + offline sync engine
//  Location: resources/js/driver/store.js
//
//  The heart of "works offline" (Scope §8.1, §12):
//
//  RECORD — record(type, payload) saves an entry in the phone's
//    outbox straight away, with its own unique id (uuid) and the time
//    it happened. It never waits for the network, so the driver can
//    keep working with no signal.
//
//  UPLOAD — syncNow() sends waiting entries in the order they were
//    recorded, 50 at a time. For each entry the server answers:
//      applied / duplicate → removed from the phone (done)
//      rejected            → kept and shown to the driver with the reason
//      failed              → the server itself had a fault: kept and sent again later
//                            (after 5 failed tries it is parked and shown to the driver)
//      retry               → not tried (an entry before it failed): kept, sent again later
//    If the signal drops mid-upload nothing is lost: the entry stays
//    until the server confirms it, and the uuid stops it being
//    recorded twice when it is sent again.
//
//  TRIPS — the server's last copy of the driver's open trips (the
//    "snapshot") is kept on the phone and refreshed whenever there is
//    signal. `view` is that copy with the waiting entries applied on top
//    (projection.js), so every screen shows what the driver just did
//    straight away. Photos upload before the entries that use them.
//
//  WHEN — on app start, the moment the signal returns, when the app
//    comes back to the screen, every minute while online, and on
//    "Upload now".
//
//  The screen reads everything from `state` (reactive): profile,
//  online, pending count, rejected, syncing, lastSync, problem.
// ══════════════════════════════════════════════════════════════════

import { computed, reactive } from 'vue';
import { liveQuery } from 'dexie';
import { api, ApiError } from './api';
import { clearAll, db, getValue, setValue } from './db';
import { photoState, removePhoto, uploadPhotos } from './photos';
import { project } from './projection';
import { setLocale, translate } from '@/lang/i18n';

export const state = reactive({
    ready: false,
    profile: null,
    online: navigator.onLine,
    pending: 0,
    rejected: [],
    syncing: false,
    lastSync: null,
    snapshot: null,
    entries: [], // waiting entries (for the projection)
    problem: null, // null | 'signedOut' | 'readOnly' | 'suspended'
    installPrompt: null,
});

const BATCH = 50;

/** After this many server faults on the same entry, it is parked for the driver instead of retried forever. */
const MAX_FAILED_TRIES = 5;

/** The pictures an entry refers to: a photo (receipt, cash, delivery note) and/or a signature. */
const picturesOf = (entry) => [entry?.payload?.photo, entry?.payload?.signature].filter(Boolean);

/** What every screen draws: trips + wallets, with waiting entries applied. */
export const view = computed(() => project(state.snapshot, state.entries));

// Language / theme chosen on the phone but not uploaded yet.
let lookOverride = {};

// ── Theme & language (applied at once, saved offline) ────────────
export function applyLook(language, theme) {
    setLocale(language);
    const light = theme === 'light';
    document.documentElement.classList.toggle('light', light);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', light ? '#EFF6FC' : '#0C1829');
}

export async function setPreference(changes) {
    state.profile = { ...state.profile, driver: { ...state.profile.driver, ...changes } };
    applyLook(state.profile.driver.language, state.profile.driver.theme);
    await setValue('profile', JSON.parse(JSON.stringify(state.profile)));
    await record('driver.preferences', changes);
}

// ── Recording ────────────────────────────────────────────────────
export async function record(type, payload = {}) {
    await db.outbox.add({
        uuid: crypto.randomUUID(),
        type,
        payload: JSON.parse(JSON.stringify(payload)),
        recordedAt: new Date().toISOString(),
        status: 'pending',
        error: null,
    });
    syncSoon();
}

/** Where the phone is right now — only when the company asked for it, never slowing the driver. */
export function place() {
    if (!state.snapshot?.settings?.capture_location || !navigator.geolocation) return Promise.resolve({});

    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (pos) => resolve({ lat: +pos.coords.latitude.toFixed(6), lng: +pos.coords.longitude.toFixed(6) }),
            () => resolve({}),
            { timeout: 4000, maximumAge: 300000, enableHighAccuracy: false },
        );
    });
}

/** Records a trip entry, adding the location for the key moments. */
export async function act(type, payload = {}, withPlace = false) {
    await record(type, withPlace ? { ...payload, ...(await place()) } : payload);
}

/** Removes a refused entry the driver has read (and its photo). */
export async function dismissRejected(id) {
    const entry = await db.outbox.get(id);
    await db.outbox.delete(id);
    for (const id of picturesOf(entry)) await removePhoto(id);
}

// ── Uploading ────────────────────────────────────────────────────
let soonTimer;
function syncSoon() {
    clearTimeout(soonTimer);
    soonTimer = setTimeout(syncNow, 800);
}

export async function syncNow() {
    if (state.syncing || !state.profile || !navigator.onLine) return;

    const first = await db.outbox.where('status').equals('pending').first();
    const photo = await db.photos.where('status').equals('pending').first();
    if (!first && !photo) return;

    state.syncing = true;
    try {
        // Refreshes the security cookie (and the session) before sending.
        await refreshProfile();

        // Photos first: an entry may only go once its photo has arrived.
        await uploadPhotos();

        for (;;) {
            const waiting = await db.outbox.where('status').equals('pending').sortBy('id');
            const batch = [];
            let parked = false;
            for (const entry of waiting) {
                if (batch.length >= BATCH) break;
                const states = await Promise.all(picturesOf(entry).map(photoState));
                // The server refused its photo (or it is lost): this entry can never go.
                // Park it for the driver to read — and keep sending everything else.
                if (states.includes('bad')) {
                    await db.outbox.update(entry.id, { status: 'rejected', error: translate('driver.photoRefused') });
                    parked = true;
                    continue;
                }
                // Its photo is merely still on the way: wait, keeping the order.
                if (states.includes('wait')) break;
                batch.push(entry);
            }
            if (!batch.length) {
                if (parked) continue;
                break;
            }

            const { results } = await api.sync(batch.map((e) => ({ uuid: e.uuid, type: e.type, payload: e.payload, recorded_at: e.recordedAt })));

            let serverFault = false;
            await db.transaction('rw', db.outbox, async () => {
                for (const r of results) {
                    const entry = batch.find((e) => e.uuid === r.uuid);
                    if (!entry) continue;
                    if (r.status === 'rejected') {
                        await db.outbox.update(entry.id, { status: 'rejected', error: r.message ?? '' });
                    } else if (r.status === 'failed') {
                        // A server fault, not a "no": keep the entry and try again later — unless it keeps failing.
                        serverFault = true;
                        const tries = (entry.failedTries ?? 0) + 1;
                        await db.outbox.update(entry.id, tries >= MAX_FAILED_TRIES
                            ? { status: 'rejected', error: translate('driver.entryFailed'), failedTries: tries }
                            : { failedTries: tries });
                    } else if (r.status === 'retry') {
                        serverFault = true; // untouched: stays waiting, in order
                    } else {
                        await db.outbox.delete(entry.id);
                    }
                }
            });

            // Applied entries are now on the server: their photos are no longer needed here.
            for (const r of results.filter((x) => x.status === 'applied' || x.status === 'duplicate')) {
                for (const id of picturesOf(batch.find((e) => e.uuid === r.uuid))) await removePhoto(id);
            }

            // The server had a fault: stop here (the next try is in a minute). Without this the loop
            // would send the same entry again and again.
            if (serverFault) break;
        }

        state.problem = null;
        state.lastSync = new Date().toISOString();
        await setValue('lastSync', state.lastSync);
    } catch (error) {
        handleProblem(error);
    } finally {
        state.syncing = false;
    }

    // What the server now holds replaces the copy on the phone.
    await refreshSnapshot();
}

// ── Trips snapshot ───────────────────────────────────────────────
let snapshotAt = 0;

export async function refreshSnapshot() {
    if (!state.profile || !navigator.onLine || state.syncing) return false;

    try {
        const snapshot = await api.snapshot();
        state.snapshot = snapshot;
        snapshotAt = Date.now();
        await setValue('snapshot', snapshot);

        return true;
    } catch (error) {
        handleProblem(error);

        return false;
    }
}

/** Every minute: upload what waits; refresh the trips every two minutes. */
async function tick() {
    await syncNow();
    if (Date.now() - snapshotAt > 120_000) await refreshSnapshot();
}

function handleProblem(error) {
    if (!(error instanceof ApiError)) throw error;
    if (error.kind === 'offline') state.online = false;
    if (['signedOut', 'readOnly', 'suspended'].includes(error.kind)) state.problem = error.kind;
}

// ── Profile ──────────────────────────────────────────────────────
async function refreshProfile() {
    const profile = await api.me();
    state.profile = { ...profile, driver: { ...profile.driver, ...pendingLook() } };
    await setValue('profile', profile);
    state.problem = profile.company.read_only ? 'readOnly' : null;
}

/** A look change not uploaded yet must win over the server's older copy. */
function pendingLook() {
    return lookOverride;
}

// ── Sign in / out ────────────────────────────────────────────────
export async function login(mobile, pin) {
    const profile = await api.login(mobile, pin);
    state.profile = profile;
    state.problem = null;
    await setValue('profile', profile);
    applyLook(profile.driver.language, profile.driver.theme);
    syncSoon();
    refreshSnapshot();
}

/** Entries (and photos) recorded on this phone that the server has not confirmed yet. */
export async function unsentCount() {
    const entries = await db.outbox.where('status').equals('pending').count();
    const photos = await db.photos.where('status').equals('pending').count();

    return { entries, photos };
}

/** Uploads what is waiting and returns how many entries are still not on the server (0 = everything is safe). */
export async function uploadAllBeforeLeaving() {
    // A sync may already be running: wait for it (at most ~30 s), then run one more.
    for (let i = 0; i < 60 && state.syncing; i++) await new Promise((r) => setTimeout(r, 500));
    await syncNow();
    for (let i = 0; i < 60 && state.syncing; i++) await new Promise((r) => setTimeout(r, 500));

    return (await unsentCount()).entries;
}

/**
 * Signs out and wipes the phone. Entries not yet on the server would be lost, so unless `force` is
 * given the sign-out is REFUSED (returns the number of entries still waiting). Returns 0 when signed out.
 */
export async function logout({ force = false } = {}) {
    if (!force) {
        const { entries, photos } = await unsentCount();
        if (entries > 0 || photos > 0) return Math.max(entries, 1);
    }

    try {
        await api.logout();
    } catch {
        // Offline: the phone is cleared anyway; the server session ends by itself.
    }
    await clearAll();
    state.profile = null;
    state.problem = null;
    state.lastSync = null;
    state.snapshot = null;
    state.entries = [];
    snapshotAt = 0;

    return 0;
}

// ── Start-up ─────────────────────────────────────────────────────
export async function boot() {
    state.profile = await getValue('profile');
    state.lastSync = await getValue('lastSync');
    state.snapshot = await getValue('snapshot');

    if (state.profile) {
        applyLook(state.profile.driver.language, state.profile.driver.theme);
    }

    liveQuery(() => db.outbox.where('status').equals('pending').count()).subscribe((n) => (state.pending = n));
    liveQuery(() => db.outbox.where('status').equals('pending').toArray()).subscribe((rows) => (state.entries = rows));
    liveQuery(() => db.outbox.where('status').equals('rejected').toArray()).subscribe((rows) => (state.rejected = rows));

    // Keep look changes made offline ahead of the server copy.
    liveQuery(() => db.outbox.where('type').equals('driver.preferences').toArray()).subscribe((rows) => {
        lookOverride = Object.assign({}, ...rows.filter((r) => r.status === 'pending').map((r) => r.payload));
    });

    window.addEventListener('online', () => { state.online = true; syncNow().then(refreshSnapshot); });
    window.addEventListener('offline', () => { state.online = false; });
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && tick());
    setInterval(tick, 60_000);

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        state.installPrompt = event;
    });

    state.ready = true;

    if (state.profile && navigator.onLine) {
        refreshProfile().then(() => syncNow().then(refreshSnapshot)).catch(handleProblem);
    }
}
