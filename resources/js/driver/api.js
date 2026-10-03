// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App: talking to the server
//  Location: resources/js/driver/api.js
//
//  A small axios client for /driver/api/*. The security (XSRF) cookie
//  is sent automatically. Every failure is turned into one of:
//    'offline'   → no signal / server unreachable (try later)
//    'signedOut' → 401: sign in again (entries stay on the phone)
//    'suspended' → 403: the office suspended the driver or company
//    'readOnly'  → 423: subscription ended (entries wait on the phone)
//    'invalid'   → 422: the form has errors (error.errors)
//    'server'    → anything else
//  Photos go one by one to /uploads (big, so a longer timeout).
// ══════════════════════════════════════════════════════════════════

import axios from 'axios';

const http = axios.create({
    baseURL: '/driver/api',
    withCredentials: true,
    withXSRFToken: true,
    timeout: 20000,
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
});

export class ApiError extends Error {
    constructor(kind, message = '', errors = {}) {
        super(message || kind);
        this.kind = kind;
        this.errors = errors;
    }
}

function wrap(error) {
    if (!error.response) return new ApiError('offline');
    const { status, data } = error.response;
    const kind = { 401: 'signedOut', 403: 'suspended', 419: 'signedOut', 422: 'invalid', 423: 'readOnly' }[status] ?? 'server';
    return new ApiError(kind, data?.message, data?.errors ?? {});
}

async function call(promise) {
    try {
        return (await promise).data;
    } catch (error) {
        throw wrap(error);
    }
}

export const api = {
    login: (mobile, pin) => call(http.post('/login', { mobile, pin })),
    me: () => call(http.get('/me')),
    snapshot: () => call(http.get('/snapshot')),
    upload: (uuid, kind, blob, when = {}) => {
        const form = new FormData();
        form.append('uuid', uuid);
        form.append('kind', kind);
        // When the picture was made and when it was saved in the app: the server refuses an old document photo.
        if (when.takenAt) form.append('taken_at', when.takenAt);
        if (when.capturedAt) form.append('captured_at', when.capturedAt);
        form.append('file', blob, `${uuid}.${blob.type === 'image/png' ? 'png' : 'jpg'}`);

        return call(http.post('/uploads', form, { timeout: 90000, headers: { 'Content-Type': 'multipart/form-data' } }));
    },
    sync: (items) => call(http.post('/sync', { items })),
    logout: () => call(http.post('/logout')),
};
