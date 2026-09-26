<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // نشان — مقادیر فقط در .env، هرگز در Git/کد:
    //   map_key     (NESHAN_MAP_KEY)     کلیدِ نقشهٔ وب (MapLibre SDK)؛ به مرورگر داده می‌شود
    //   service_key (NESHAN_SERVICE_KEY) کلیدِ وب‌سرویسِ «تبدیل آدرس به نقطه»؛ فقط سمتِ سرور (proxy)، هرگز به مرورگر
    //   geocoding_plus (NESHAN_GEOCODING_PLUS) true = استفاده از Geocoding Plus (باید رویِ همان کلید فعال باشد)
    'neshan' => [
        'map_key' => env('NESHAN_MAP_KEY'),
        'service_key' => env('NESHAN_SERVICE_KEY'),
        'geocoding_plus' => env('NESHAN_GEOCODING_PLUS', false),
    ],

];
