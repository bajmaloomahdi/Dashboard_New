<?php

/*
|--------------------------------------------------------------------------
| Web Push (اعلانِ گوشی/مرورگر در زمانِ بسته‌بودنِ برنامه)
|--------------------------------------------------------------------------
| کلیدهایِ VAPID فقط در .env هر نصب نگه‌داری می‌شوند (هر مشتری کلیدِ اختصاصیِ خودش)؛ تولید: php artisan push:vapid-keys
| تغییرِ کلیدها همهٔ اشتراک‌هایِ موجود را بی‌اعتبار می‌کند.
| تحویل «حداقل یک‌بار» است؛ تحویل در همهٔ شرایط (مرورگر، سیستم‌عامل، شبکه، تنظیماتِ دستگاه) تضمین نمی‌شود.
*/

return [
    // سوییچِ کلیِ ارسال (پردازشگرِ پس‌زمینه). ثبتِ اشتراک مستقل از این است؛ مرزِ شروع با php artisan push:activate ثبت می‌شود.
    'enabled' => (bool) env('PUSH_ENABLED', false),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),          // mailto:... یا https://...
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // فقط HTTPS به این میزبان‌ها (جلوگیری از SSRF). «.» در ابتدا یعنی هر زیرمیزبان.
    'allowed_hosts' => [
        'fcm.googleapis.com',            // Chrome / Edge در اندروید
        'web.push.apple.com',            // Safari / iPhone / iPad
        '.push.apple.com',
        'updates.push.services.mozilla.com', // Firefox
        '.push.services.mozilla.com',
        '.notify.windows.com',           // Edge (ویندوز)
    ],

    // null = بدونِ محدودیتِ سن (تصمیمِ مرحلهٔ اول)؛ عدد = رویدادِ قدیمی‌تر از این تعداد دقیقه SKIPPED_STALE می‌شود
    'max_age_minutes' => env('PUSH_MAX_AGE_MINUTES') !== null && env('PUSH_MAX_AGE_MINUTES') !== ''
        ? (int) env('PUSH_MAX_AGE_MINUTES') : null,

    'batch_size' => (int) env('PUSH_BATCH_SIZE', 50),
    'lease_seconds' => (int) env('PUSH_LEASE_SECONDS', 300),
    'max_attempts' => (int) env('PUSH_MAX_ATTEMPTS', 5),
    'backoff_base_seconds' => 60,       // 1، 2، 4، 8، ... دقیقه
    'backoff_max_seconds' => 3600,
    'http_timeout_seconds' => 10,
    'http_connect_timeout_seconds' => 5,
    'run_budget_seconds' => 50,        // هر اجرایِ دقیقه‌ای حداکثر این‌قدر کار می‌کند
    'retention_days' => (int) env('PUSH_RETENTION_DAYS', 30),

    // مدتِ نگه‌داری در سرویسِ Push برایِ دستگاهِ خاموش/آفلاین
    'ttl_seconds' => [
        'MESSAGE' => 172800,
        'TASK' => 172800,
        'REMINDER' => 21600,
        'TEST' => 3600,
        'BROADCAST' => 86400,
    ],
];
