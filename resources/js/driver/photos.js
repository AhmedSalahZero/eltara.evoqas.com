// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App: photos (receipts, cash, delivery note)
//  Location: resources/js/driver/photos.js
//
//  A photo taken with no signal is shrunk (about 1600 px, under
//  ~0.5 MB — a phone camera gives 5–10 MB), saved in the phone's
//  database and given its own id (uuid). The entry that uses it
//  carries only that id. uploadPhotos() sends the waiting photos;
//  the same id sent twice is harmless on the server.
// ══════════════════════════════════════════════════════════════════

import imageCompression from 'browser-image-compression';
import { api, ApiError } from './api';
import { db } from './db';

/** A document photo must have been taken this recently (minutes). Keep equal to config eltara.uploads.photo_max_age_minutes. */
export const MAX_PHOTO_AGE_MINUTES = 15;

/**
 * Was this picture taken just now? A photo picked from the gallery keeps the time it was really taken,
 * so an old receipt is recognised. When the phone gives no time at all we cannot tell, and accept it.
 */
export function isFreshPhoto(file) {
    const made = Number(file?.lastModified);
    if (!made) return true;

    return Date.now() - made <= MAX_PHOTO_AGE_MINUTES * 60 * 1000;
}

/** Shrinks and saves a photo; returns its id. */
export async function savePhoto(file, kind) {
    let blob = file;
    try {
        blob = await imageCompression(file, { maxSizeMB: 0.5, maxWidthOrHeight: 1600, useWebWorker: false, // a worker would download its code from a CDN: blocked by the security policy and impossible offline
            initialQuality: 0.8, fileType: 'image/jpeg' });
    } catch {
        // Could not shrink (rare): keep the original, the server accepts up to 6 MB.
    }

    const uuid = crypto.randomUUID();
    const takenAt = file?.lastModified ? new Date(Number(file.lastModified)).toISOString() : null;
    await db.photos.add({ uuid, kind, blob, status: 'pending', createdAt: new Date().toISOString(), takenAt });

    return uuid;
}

/** Saves a picture that needs no shrinking (a signature). */
export async function savePhotoBlob(blob, kind) {
    const uuid = crypto.randomUUID();
    await db.photos.add({ uuid, kind, blob, status: 'pending', createdAt: new Date().toISOString() });

    return uuid;
}

export async function removePhoto(uuid) {
    if (uuid) await db.photos.delete(uuid);
}

/** Sends every waiting photo. Stops at the first network problem. */
export async function uploadPhotos() {
    const waiting = await db.photos.where('status').equals('pending').toArray();

    for (const photo of waiting) {
        try {
            await api.upload(photo.uuid, photo.kind, photo.blob, { takenAt: photo.takenAt, capturedAt: photo.createdAt });
            await db.photos.update(photo.uuid, { status: 'sent' });
        } catch (error) {
            // The server refuses this file itself (too big / not an image):
            // keep going so one bad photo never blocks the others.
            if (error instanceof ApiError && error.kind === 'invalid') {
                await db.photos.update(photo.uuid, { status: 'bad' });
                continue;
            }
            throw error;
        }
    }
}

/** Has this photo reached the server? (No photo = nothing to wait for.) */
export async function isSent(uuid) {
    if (!uuid) return true;
    const row = await db.photos.get(uuid);

    return row?.status === 'sent';
}

/**
 * Where is this photo? 'sent' (on the server), 'wait' (still to upload)
 * or 'bad' (the server refused it, or it is gone from the phone — it can
 * never be sent, so the entry that needs it must be parked, not awaited).
 */
export async function photoState(uuid) {
    if (!uuid) return 'sent';
    const row = await db.photos.get(uuid);
    if (!row) return 'bad';

    return row.status === 'sent' ? 'sent' : row.status === 'bad' ? 'bad' : 'wait';
}

/** A temporary address to show a saved photo on screen. */
export async function photoUrl(uuid) {
    const row = uuid ? await db.photos.get(uuid) : null;

    return row ? URL.createObjectURL(row.blob) : null;
}
