// ══════════════════════════════════════════════════════════════════
//  El Tara — Office / Admin / Client portal entry point
//  Location: resources/js/app.js
//
//  Starts Vue 3 + Inertia for every page rendered by Laravel:
//    · screens live in resources/js/Pages/<Name>.vue
//    · route('office.users.index') works in every screen (Ziggy)
//    · language, direction and theme are applied from the first
//      page before anything is drawn (usePreferences)
//    · the page-loading bar uses El Tara green
//  The Driver App is a separate program: resources/js/driver/main.js.
// ══════════════════════════════════════════════════════════════════

import '../css/app.css';
import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { initPreferences } from '@/composables/usePreferences';
import { setLocale } from '@/lang/i18n';
import { registerServiceWorker } from '@/pwa/register';

const appName = import.meta.env.VITE_APP_NAME || 'El Tara';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),

    setup({ el, App, props, plugin }) {
        initPreferences(props.initialPage.props);

        // Keep the language in step with the server after every visit.
        router.on('success', (event) => setLocale(event.detail.page.props.locale));

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },

    progress: { color: '#26C08C', showSpinner: false },
});

// Makes El Tara installable on phones (it never stores office pages).
registerServiceWorker();
