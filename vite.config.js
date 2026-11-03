import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/css/signed-in.css', 'resources/js/app.js', 'resources/css/dot-grid.css', 'resources/js/grid/main.js', 'resources/css/dot-pages.css', 'resources/js/pages/shell.js', 'resources/js/pages/dashboard.js'],
            refresh: true,
        }),
    ],
});
