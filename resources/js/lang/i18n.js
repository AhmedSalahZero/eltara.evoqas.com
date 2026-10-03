// ══════════════════════════════════════════════════════════════════
//  El Tara — Translations helper
//  Location: resources/js/lang/i18n.js
//
//  const { t, locale, isAr } = useI18n()
//  t('users.add')                    → "مستخدم جديد" / "New user"
//  t('admin.allowed', { n: 5 })      → fills {n}
//  tr('set.policies.limit')          → the raw value (e.g. [title, subtitle])
//
//  The language is one shared value (currentLocale). The office /
//  client screens set it from the server (props.locale); the Driver
//  App sets it from the driver's own choice. Switching it redraws
//  every screen at once — and sets <html dir> for right-to-left.
//  A missing key shows the key itself, so it is easy to spot.
// ══════════════════════════════════════════════════════════════════

import { computed, ref } from 'vue';
import ar from './ar.js';
import en from './en.js';

const dictionaries = { ar, en };

export const currentLocale = ref(document.documentElement.lang === 'en' ? 'en' : 'ar');

export function setLocale(locale) {
    currentLocale.value = locale === 'en' ? 'en' : 'ar';
    document.documentElement.lang = currentLocale.value;
    document.documentElement.dir = currentLocale.value === 'ar' ? 'rtl' : 'ltr';
}

export function translate(key, params = {}, locale = currentLocale.value) {
    const value = key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), dictionaries[locale]);

    if (typeof value !== 'string') {
        return key;
    }

    return value.replace(/\{(\w+)\}/g, (match, name) => (params[name] ?? match));
}

/** The raw value at a key (e.g. a [title, subtitle] pair). */
export function raw(key, locale = currentLocale.value) {
    return key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), dictionaries[locale]);
}

export function useI18n() {
    return {
        t: (key, params) => translate(key, params, currentLocale.value),
        tr: (key) => raw(key, currentLocale.value),
        locale: currentLocale,
        isAr: computed(() => currentLocale.value === 'ar'),
    };
}
