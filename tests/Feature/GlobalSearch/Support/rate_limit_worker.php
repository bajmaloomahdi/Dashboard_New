<?php

/*
 * پردازشِ کارگر برایِ آزمونِ هم‌زمانیِ محدودیتِ نرخ (RedisRateLimitIntegrationTest).
 * اتصال از متغیرهایِ محیطیِ پردازشِ فرزند (GSW_HOST / GSW_PORT / GSW_PASSWORD) خوانده می‌شود — رمز هرگز در
 * خطِ فرمان نمی‌آید. فقط شمارش‌ها و نامِ کلاسِ خطا چاپ می‌شوند.
 *
 * argv: userKey attempts maxAttempts decaySeconds startAtMicrotime
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Services\GlobalSearch\RateLimit\RedisSearchRateLimiter;

[, $userKey, $attempts, $max, $decay, $startAt] = $argv;

$limiter = new RedisSearchRateLimiter([
    'host' => (string) getenv('GSW_HOST'),
    'port' => (int) getenv('GSW_PORT'),
    'password' => (string) getenv('GSW_PASSWORD'),
    'timeout' => 2.0,
    'read_write_timeout' => 2.0,
    'prefix' => 'gs:rl:v1:',
]);

// همهٔ کارگرها هم‌زمان شروع می‌کنند
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

$allowed = 0;
$blocked = 0;
$errors = [];
$maxCount = 0;
for ($i = 0; $i < (int) $attempts; $i++) {
    try {
        $r = $limiter->attempt($userKey, (int) $max, (int) $decay);
        $r->allowed ? $allowed++ : $blocked++;
        $maxCount = max($maxCount, $r->count);
    } catch (\Throwable $e) {
        $errors[] = $e::class;
    }
}

echo json_encode(['allowed' => $allowed, 'blocked' => $blocked, 'errors' => $errors, 'maxCount' => $maxCount]);
