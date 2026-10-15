<?php

/*
 * جست‌وجوی سراسری (Global Search) — پیش‌فرض خاموش.
 * روشن‌کردن در هر استقرار فقط با GLOBAL_SEARCH_ENABLED=true و تأیید جداگانه.
 * مشخصات: GlobalSearch_Spec_AccessMatrix.md (نسخهٔ 1.0)
 */

// فلگ‌ها (یافتهٔ ۵ ممیزی): فقط مقدارِ صریحِ true روشن می‌کند — یعنی true یا (true) با هر بزرگی و کوچکیِ حروف،
// که env() لاراول به بولیِ true تبدیل می‌کند. هر مقدارِ دیگر (off، no، 1، yes، on، رشتهٔ نامعتبر، خالی) خاموش است.
$flag = static fn (string $name): bool => env($name, false) === true;

return [

    // خاموش: مسیر /global-search پاسخ 404 می‌دهد و UI دکمه را نشان نمی‌دهد (features.globalSearch)
    'enabled' => $flag('GLOBAL_SEARCH_ENABLED'),

    // اتصالِ دیتابیسِ جست‌وجو: sqlsrv_search (config/database.php) — بدونِ Pooling تا Query Timeout حتی رویِ
    // اولین دستورِ اتصال اعمال شود. AccessContext (مجوزها) همچنان از اتصالِ پیش‌فرض خوانده می‌شود.
    'connection' => 'sqlsrv_search',

    'query' => [
        // طولِ ورودیِ خام (پیش از نرمال‌سازی) — جلوگیری از بارِ بی‌مورد
        'max_raw_length' => 200,
        // طولِ پس از نرمال‌سازی (§3 مشخصات)
        'min_length' => 2,
        'max_length' => 100,
        // حداکثر تعداد توکن‌ها (کلمه‌ها)
        'max_tokens' => 6,
    ],

    'limits' => [
        // حداکثر نتیجه در هر گروه (پس از اعمالِ دسترسی)
        'per_group' => 5,
        // حداکثر کلِ نتایج در یک پاسخ
        'total' => 30,
        // بودجهٔ زمانیِ کلِ درخواست (میلی‌ثانیه)؛ پس از عبور، گروه‌های بعدی اجرا نمی‌شوند
        'time_budget_ms' => 1500,
    ],

    'rate_limit' => [
        // تعداد درخواستِ مجاز برای هر کاربر در هر بازه
        'max_attempts' => 20,
        'decay_seconds' => 10,

        // redis (پیش‌فرض): شمارندهٔ اتمیک (INCR + PEXPIRE در یک اسکریپتِ Lua) با اتصالِ مستقل؛ fail-closed (503).
        // cache: فقط برایِ آزمون‌هایِ بدونِ Redis — اتمیک نیست. هر مقدارِ دیگر = redis.
        'driver' => env('GLOBAL_SEARCH_RATE_LIMIT_DRIVER', 'redis'),

        // فقط برایِ driver = cache (Store جدا از دیتابیس)
        'store' => env('GLOBAL_SEARCH_RATE_LIMIT_STORE', 'array'),

        // اتصالِ مستقل به Redis/Garnetِ محدودیتِ نرخ — جدا از REDIS_*ِ برنامه و از Redisِ اصلی.
        // رمز فقط در ‎.env؛ بدونِ رمز، محدودکننده در دسترس نیست و جست‌وجو 503 می‌دهد.
        'redis' => [
            'host' => env('GS_RATELIMIT_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('GS_RATELIMIT_REDIS_PORT', 6390),
            'password' => env('GS_RATELIMIT_REDIS_PASSWORD'),
            'timeout' => 0.5,             // اتصال (ثانیه)
            'read_write_timeout' => 0.5,  // هر فرمان (ثانیه)
            'prefix' => 'gs:rl:v1:',
        ],
    ],

    /*
     * گروه‌های سمتِ سرور. هر گروه یک کلاسِ SearchProvider دارد و جداگانه روشن می‌شود.
     * هر چهار گروه پیاده‌سازی شده‌اند و هرکدام جداگانه پیش‌فرض خاموش‌اند (GLOBAL_SEARCH_*_ENABLED).
     * ترتیبِ این آرایه = ترتیبِ نمایش (§5 مشخصات).
     */
    'providers' => [
        'crm' => [
            'enabled' => $flag('GLOBAL_SEARCH_CRM_ENABLED'),
            'class' => \App\Services\GlobalSearch\Providers\CrmSearchProvider::class,
        ],
        'messages' => [
            'enabled' => $flag('GLOBAL_SEARCH_MESSAGES_ENABLED'),
            'class' => \App\Services\GlobalSearch\Providers\MessageSearchProvider::class,
        ],
        'projects' => [
            'enabled' => $flag('GLOBAL_SEARCH_PROJECTS_ENABLED'),
            'class' => \App\Services\GlobalSearch\Providers\ProjectSearchProvider::class,
        ],
        'processes' => [
            'enabled' => $flag('GLOBAL_SEARCH_PROCESSES_ENABLED'),
            'class' => \App\Services\GlobalSearch\Providers\ProcessSearchProvider::class,
        ],
    ],
];
