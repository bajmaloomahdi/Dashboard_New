import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],

            // غیرفعال کردن رفرش ناخواسته صفحه
            refresh: false,
        }),

        react(),
    ],

    server: {
        // Vite روی تمام Interfaceها گوش می‌دهد
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,

        // اجازه دسترسی از IP عمومی
        cors: true,
        allowedHosts: true,

        // آدرس واقعی Vite برای مرورگر
        origin: 'http://86.104.80.90:5173',

        // HMR از طریق IP سرور
        hmr: {
            host: '86.104.80.90',
            port: 5173,
        },

        // بهینه‌سازی Watch در Docker روی Windows
        watch: {
            usePolling: true,
            interval: 1500,
            binaryInterval: 3000,

            ignored: [
                '**/storage/**',
                '**/vendor/**',
                '**/node_modules/**',
                '**/.git/**',
                '**/public/build/**',
                '**/bootstrap/cache/**',
                '**/.env*',
            ],
        },
    },
});