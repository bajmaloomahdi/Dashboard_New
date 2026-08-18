import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
    ],

    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
            port: 5173,
        },
        watch: {
            usePolling: true,   // ← تشخیص تغییرات فایل در داکر روی ویندوز
            interval: 300,      // هر ۳۰۰ میلی‌ثانیه چک می‌کند
            ignored: ['**/vendor/**', '**/storage/**', '**/node_modules/**'],
        },
    },
});