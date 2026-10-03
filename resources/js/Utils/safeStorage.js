// ══════════════════════════════════════════════════════════════════
//  El Tara — safeStorage (remember small things on this browser, safely)
//  Location: resources/js/Utils/safeStorage.js
//
//  A browser can refuse to store anything (private mode, blocked
//  cookies, full storage, some in-app browsers). Reading or writing
//  then throws an error that would stop the whole screen from
//  loading. These two helpers never throw: a failed read gives the
//  fallback, a failed write is simply ignored.
//
//    storageGet('eltara.theme', 'dark')   → saved value, or 'dark'
//    storageSet('eltara.theme', 'light')  → true if saved, false if not
// ══════════════════════════════════════════════════════════════════

export function storageGet(key, fallback = null) {
    try {
        const value = window.localStorage.getItem(key);

        return value === null ? fallback : value;
    } catch {
        return fallback;
    }
}

export function storageSet(key, value) {
    try {
        window.localStorage.setItem(key, value);

        return true;
    } catch {
        return false;
    }
}
