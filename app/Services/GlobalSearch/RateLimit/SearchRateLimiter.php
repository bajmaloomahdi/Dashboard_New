<?php

namespace App\Services\GlobalSearch\RateLimit;

/**
 * محدودیتِ نرخِ جست‌وجو برایِ هر کاربر.
 *
 * قرارداد: attempt() هر فراخوانی را یک درخواست می‌شمارد و نتیجه را برمی‌گرداند. اگر پشتیبان (Redis) در دسترس
 * نباشد، خطا بدهد، Timeout شود یا حافظه‌اش پر باشد، باید استثنا پرتاب کند — هرگز «مجاز» برنگرداند.
 * ThrottleGlobalSearch در آن حالت پاسخِ کنترل‌شدهٔ 503 می‌دهد و جست‌وجو اجرا نمی‌شود (fail-closed).
 */
interface SearchRateLimiter
{
    public function attempt(string $userKey, int $maxAttempts, int $decaySeconds): RateLimitResult;
}
