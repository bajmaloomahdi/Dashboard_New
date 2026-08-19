import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
            ],
            refresh: true,
        }),
        react(),
    ],

    server: {
        // Vite روی تمام Interfaceها گوش بدهد
        host: '0.0.0.0',

        port: 5173,
        strictPort: true,

        // اجازه دسترسی از سیستم‌های دیگر شبکه
        cors: true,

        // آدرس واقعی که مرورگر کلاینت باید فایل‌های Vite را از آن بگیرد
        origin: `http://${process.env.VITE_HOST}:5173`,

        // HMR برای سیستم‌های دیگر شبکه
        hmr: {
            host: process.env.VITE_HOST,
            port: 5173,
            protocol: 'ws',
        },

        watch: {
            usePolling: true,
            interval: 1000,
            binaryInterval: 3000,

            ignored: [
                '**/vendor/**',
                '**/storage/**',
                '**/node_modules/**',
                '**/.git/**',
                '**/public/build/**',
                '**/bootstrap/cache/**',
            ],
        },
    },
});