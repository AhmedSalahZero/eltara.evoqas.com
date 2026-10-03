// ══════════════════════════════════════════════════════════════════
//  El Tara — Permission checks in screens
//  Location: resources/js/composables/usePermissions.js
//
//  const { can } = usePermissions();   v-if="can('users.create')"
//
//  Only HIDES buttons and menu items the person cannot use. The real
//  protection is always on the server (routes use can:… and every
//  action checks again) — hiding a button is a courtesy, not security.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
    const page = usePage();
    const keys = computed(() => new Set(page.props.auth?.user?.permissions ?? []));

    return {
        can: (key) => keys.value.has(key),
        canAny: (list) => list.some((key) => keys.value.has(key)),
    };
}
