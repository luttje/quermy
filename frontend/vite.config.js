import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
    plugins: [svelte()],
    build: {
        // Build straight into the PHP public dir so the whole thing is one
        // drop-in folder.
        outDir: '../backend/public',
        emptyOutDir: false,         // keep index.php / .htaccess
        assetsDir: 'assets',
    },
    server: {
        port: 5173,
        proxy: {
            // During `npm run dev` proxy /api to a PHP dev server you start
            // with: `php -S localhost:8000 -t backend/public`
            '/api': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
        },
    },
});
