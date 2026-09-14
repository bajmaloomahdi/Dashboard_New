<?php

/*
|--------------------------------------------------------------------------
| موتور فرایند (Workflow Engine) — پیکربندی فاز ۱
|--------------------------------------------------------------------------
|
| اتصال موتور به ماژول‌های کسب‌وکار فقط از طریقِ EntityType + EntityID انجام
| می‌شود؛ هیچ Foreign Key ای به جداولِ ماژول‌ها وجود ندارد. این فایل نقشِ
| «رجیستریِ EntityProviderها» را در فاز ۱ بازی می‌کند (معادلِ جدولِ
| WorkflowEntityProviders در فازهای بعد). قرارداد EntityResolver ثابت می‌ماند.
|
*/

return [

    /*
    | حداکثر تعداد گذارها (Transition) در طولِ عمرِ یک Instance — نگهبانِ حلقه.
    | با رسیدن به این سقف، موتور Instance را FAILED می‌کند.
    */
    'max_transitions_per_instance' => (int) env('WORKFLOW_MAX_TRANSITIONS', 500),

    /*
    | کدِ تقویمِ کاری پیش‌فرض که سرویس‌های SLA/مهلت از آن استفاده می‌کنند.
    */
    'default_calendar_code' => env('WORKFLOW_DEFAULT_CALENDAR', 'DEFAULT'),

    /*
    |--------------------------------------------------------------------------
    | EntityProviderها
    |--------------------------------------------------------------------------
    |
    | کلید = EntityType (همان مقداری که در WorkflowDefinitions.EntityType و
    | WorkflowInstances.EntityType ذخیره می‌شود).
    |
    | هر Provider مشخص می‌کند موتور چگونه دربارهٔ یک موجودیت اطلاعات بگیرد:
    |   - resolver : کلاسی که Contracts\EntityResolver را پیاده می‌کند
    |                (برای فاز ۱ همه از NullEntityResolver استفاده می‌کنند مگر
    |                 اینکه ماژول، Resolver اختصاصی معرفی کند).
    |   - title    : الگوی عنوانِ نمایشیِ موجودیت (اختیاری)
    |   - label    : برچسبِ فارسیِ نوع موجودیت (برای UI فازهای بعد)
    |
    */
    'entities' => [

        'MESSAGE' => [
            'label'    => 'نامه / وظیفه',
            'resolver' => \App\Services\Workflow\Entity\NullEntityResolver::class,
        ],

        'PROJECT' => [
            'label'    => 'پروژه',
            'resolver' => \App\Services\Workflow\Entity\NullEntityResolver::class,
        ],

    ],

];
