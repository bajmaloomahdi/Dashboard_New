import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            // غیرفعال کردن رفرش ناخواسته صفحه برای جلوگیری از پریدن به لاگین
            refresh: false,
        }),
        react(),
    ],

    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,

        // اجازه دسترسی بدون محدودیت
        cors: true,
        allowedHosts: true,

        // تنظیم قطعی سوکت روی لوکال‌هاست برای جلوگیری از تداخل آی‌پی‌ها و قطع سوکت
        hmr: {
            host: 'localhost',
            port: 5173,
        },

        // تنظیمات بهینه‌سازی خواندن فایل در داکر روی ویندوز
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