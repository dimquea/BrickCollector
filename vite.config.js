import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
    ],
    experimental: {
        // Шрифты и картинки, на которые ссылается CSS, адресуем относительно
        // самого файла стилей. Абсолютный /build/... ломается, когда приложение
        // живёт под префиксом — например, под Ingress в аддоне Home Assistant.
        renderBuiltUrl(filename, { hostType }) {
            return hostType === 'css' ? { relative: true } : { relative: false };
        },
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
