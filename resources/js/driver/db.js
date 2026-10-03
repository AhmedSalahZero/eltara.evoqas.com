// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App: the phone's private database (IndexedDB)
//  Location: resources/js/driver/db.js
//
//  Everything the Driver App keeps on the phone, using Dexie (a
//  friendly layer over the browser's built-in database):
//
//    outbox → entries recorded on the phone, waiting to upload:
//             { id, uuid, type, payload, recordedAt, status, error }
//             status: 'pending' (to upload) | 'rejected' (office said no)
//    kv     → small saved values: the driver's profile, last upload
//             time, the trips snapshot … { key, value }
//    photos → receipt / delivery-note photos waiting to upload (version 2):
//             { uuid, kind, blob, status, createdAt }. A photo is sent
//             first, then the entry that refers to it by its uuid; once
//             the entry is confirmed the photo is deleted from the phone.
//
//  This storage belongs to this phone and this site only. It is
//  wiped when the driver signs out (clearAll), so the next person
//  using the phone sees nothing of the previous driver.
//
//  New tables are added with a new db.version(N).stores({...}) —
//  never by changing an earlier version.
// ══════════════════════════════════════════════════════════════════

import Dexie from 'dexie';

export const db = new Dexie('eltara-driver');

db.version(1).stores({
    outbox: '++id, &uuid, status, type',
    kv: '&key',
});

db.version(2).stores({
    photos: '&uuid, status',
});

export async function getValue(key, fallback = null) {
    return (await db.kv.get(key))?.value ?? fallback;
}

export async function setValue(key, value) {
    await db.kv.put({ key, value });
}

export async function clearAll() {
    await db.transaction('rw', db.outbox, db.kv, db.photos, async () => {
        await db.outbox.clear();
        await db.kv.clear();
        await db.photos.clear();
    });
}
