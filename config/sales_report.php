<?php

return [
    'user_ids' => array_filter(explode(',', (string) env('SALES_REPORT_USER_IDS', '')), 'strlen'),
    'host' => env('SALES_REPORT_DB_HOST', env('DB_HOST', 'localhost')),
    'port' => env('SALES_REPORT_DB_PORT', env('DB_PORT', 1433)),
    'username' => env('SALES_REPORT_DB_USERNAME'),
    'password' => env('SALES_REPORT_DB_PASSWORD'),
    'use_project_credentials' => env('SALES_REPORT_USE_PROJECT_CREDENTIALS', false),
];
