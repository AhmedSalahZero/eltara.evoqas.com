// ══════════════════════════════════════════════════════════════════
//  El Tara — Language + theme (office, admin and client portals)
//  Location: resources/js/composables/usePreferences.js
//
//  applyTheme('light') → switches the colours at once (html.light)
//  savePreference({ language: 'en' }) → switches on screen AND saves
//  it on the person's account (POST /preferences), so it follows them
//  to any computer. Guests (sign-in page) get it saved in the session.
//  The Driver App has its own version that also works offline
//  (resources/js/driver/store.js).
// ══════════════════════════════════════════════════════════════════

import { router, usePage } from '@inertiajs/vue3';
import { setLocale } from '@/lang/i18n';
import { storageGet, storageSet } from '@/Utils/safeStorage';
import { setTimezone } from '@/Utils/format';

export function applyTheme(theme) {
    const light = theme === 'light';
    document.documentElement.classList.toggle('light', light);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', light ? '#EFF6FC' : '#0C1829');
}

export function initPreferences(props) {
    setLocale(props.locale);
    setTimezone(props.timezone);
    applyTheme(props.auth?.user?.theme ?? (storageGet('eltara.theme') || 'dark'));
}

export function usePreferences() {
    const page = usePage();

    function savePreference(changes) {
        if (changes.theme) {
            applyTheme(changes.theme);
            storageSet('eltara.theme', changes.theme);
        }
        if (changes.language) {
            setLocale(changes.language);
        }

        const portal = page.props.auth?.portal === 'client' ? { portal: 'client' } : {};

        router.post(route('preferences.update'), { ...changes, ...portal }, { preserveScroll: true, preserveState: true });
    }

    return { savePreference };
}
