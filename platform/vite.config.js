import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        host: '127.0.0.1',
        port: 5173,
        hmr: {
            host: '127.0.0.1',
        },
    },
    plugins: [
        laravel({
            input: ['resources/js/app.js', 'resources/css/app.css'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            registerType: 'autoUpdate',
            // Registration is explicit in resources/js/app.js so the app owns its lifecycle.
            injectRegister: false,
            // The web manifest is served statically from public/manifest.webmanifest
            // (single source of truth, works even before an asset build).
            manifest: false,
            workbox: {
                // App-shell assets are precached; navigations use a network-first
                // strategy with the cached shell as the offline fallback.
                navigateFallback: null,
                globPatterns: ['**/*.{js,css,ico,png,svg,woff2}'],
                runtimeCaching: [
                    {
                        // HTML documents: try network, fall back to cache when offline.
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'pages-cache',
                            networkTimeoutSeconds: 4,
                            expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 },
                        },
                    },
                    {
                        urlPattern: ({ url }) => url.pathname.startsWith('/api/v1/catalog'),
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'catalog-cache',
                            expiration: { maxEntries: 60, maxAgeSeconds: 60 * 60 * 24 },
                        },
                    },
                    {
                        urlPattern: ({ url }) => url.pathname.startsWith('/api/'),
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'api-cache',
                            networkTimeoutSeconds: 5,
                            expiration: { maxEntries: 50, maxAgeSeconds: 60 * 5 },
                        },
                    },
                    {
                        urlPattern: ({ request }) => request.destination === 'font',
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'font-cache',
                            expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 * 365 },
                        },
                    },
                ],
            },
        }),
    ],
    build: {
        // Strict lazy-loading: split every Inertia page into its own chunk.
        rollupOptions: {
            output: {
                manualChunks: {
                    vendor: ['vue', '@inertiajs/vue3', 'vue-i18n'],
                },
            },
        },
    },
});