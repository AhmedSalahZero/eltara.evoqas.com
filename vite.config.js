// ══════════════════════════════════════════════════════════════════
//  El Tara — Vite (builds the screens)
//  Location: vite.config.js
//
//  `npm run dev`   → while working: screens reload as files change
//  `npm run build` → for real use: writes everything to public/build
//
//  Two apps are built:
//    resources/js/app.js         → office, Super Admin and client portal
//                                  (Vue 3 + Inertia)
//    resources/js/driver/main.js → the Driver App (Vue 3, works offline)
//
//  Plugins:
//    tailwindcss() → Tailwind CSS v4
//    vue()         → Vue single-file screens (.vue)
//    VitePWA()     → makes El Tara installable on a phone and builds the
//                    offline helper (service worker) from
//                    resources/js/pwa/sw.js. It lands in
//                    public/build/sw.js and is served at /sw.js by
//                    App\Http\Controllers\PwaController.
//
//  '@' means resources/js, so imports read
//  `import AppIcon from '@/Components/AppIcon.vue'`.
// ══════════════════════════════════════════════════════════════════

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            'ziggy-js': fileURLToPath(new URL('./vendor/tightenco/ziggy', import.meta.url)),
        },
    },

    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/driver/main.js'],
            refresh: true,
        }),

        tailwindcss(),

        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),

        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js/pwa',
            filename: 'sw.js',
            outDir: 'public/build',
            buildBase: '/build/',
            scope: '/',
            base: '/',
            injectRegister: false,
            manifestFilename: 'manifest.webmanifest',
            injectManifest: {
                globDirectory: 'public/build',
                globPatterns: ['**/*.{js,css,woff2,png,svg,ico,webmanifest}'],
                // Only the fonts actually used: Latin + Arabic.
                globIgnores: ['**/*-cyrillic*', '**/*-greek*', '**/*-vietnamese*', '**/*.woff'],
                modifyURLPrefix: { '': '/build/' },
                maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
            },
            manifest: {
                id: '/',
                name: 'التارة — El Tara',
                short_name: 'التارة',
                description: 'كل مشوار محسوب — Every trip counted',
                lang: 'ar',
                dir: 'rtl',
                start_url: '/',
                scope: '/',
                display: 'standalone',
                orientation: 'portrait',
                background_color: '#0C1829',
                theme_color: '#0C1829',
                icons: [
                    { src: '/images/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/images/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                    { src: '/images/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
            },
        }),
    ],
});
