import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),

        tailwindcss(),

        VitePWA({
            // 'autoUpdate' atualiza o SW silenciosamente em background
            registerType: 'autoUpdate',

            // Gera o service worker via workbox
            workbox: {
                // Faz cache de todos os assets gerados pelo Vite
                globPatterns: ['**/*.{js,css,html,ico,png,svg,webp,woff,woff2}'],

                // Rotas que o SW deve servir offline (network-first)
                runtimeCaching: [
                    {
                        urlPattern: /^https:\/\/fonts\.bunny\.net\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'bunny-fonts',
                            expiration: {
                                maxEntries: 10,
                                maxAgeSeconds: 60 * 60 * 24 * 365, // 1 ano
                            },
                            cacheableResponse: { statuses: [0, 200] },
                        },
                    },
                    {
                        // Páginas da aplicação — network-first com fallback offline
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'pages',
                            networkTimeoutSeconds: 5,
                        },
                    },
                ],
            },

            // Manifest da PWA
            manifest: {
                name: 'OFX Tracker',
                short_name: 'OFX Tracker',
                description: 'Acompanhamento de pagamentos e gestão de membros',
                theme_color: '#2563eb',
                background_color: '#ffffff',
                display: 'standalone',
                orientation: 'portrait',
                scope: '/',
                start_url: '/dashboard',
                lang: 'pt-BR',
                icons: [
                    {
                        src: '/icons/pwa-192x192.png',
                        sizes: '192x192',
                        type: 'image/png',
                    },
                    {
                        src: '/icons/pwa-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                    },
                    {
                        src: '/icons/pwa-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
            },

            // Desativa o PWA em dev para não interferir no HMR
            devOptions: {
                enabled: false,
            },
        }),
    ],

    build: {
        // Aumenta o limite de aviso de chunk (padrão 500kb é baixo para apps com Flux)
        chunkSizeWarningLimit: 1024,

        rollupOptions: {
            output: {
                // Rolldown (Vite 8) exige manualChunks como função
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        return 'vendor';
                    }
                },
            },
        },
    },

    server: {
        cors: true,
        watch: {
            // Ignora views compiladas para não recarregar desnecessariamente
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
