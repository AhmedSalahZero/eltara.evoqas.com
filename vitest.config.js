// ══════════════════════════════════════════════════════════════════
//  El Tara — Vitest (Vue component tests)
//  Location: vitest.config.js
//
//  Separate from vite.config.js so the Laravel and PWA plugins are
//  not loaded. `npm test` runs every resources/js/**/*.spec.js file.
// ══════════════════════════════════════════════════════════════════

import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.spec.js'],
    },
});
