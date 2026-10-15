<?php

namespace App\Services\GlobalSearch\RateLimit;

/** پشتیبانِ محدودیتِ نرخ پیکربندی نشده یا پاسخِ نامعتبر داده است؛ ThrottleGlobalSearch آن را 503 می‌کند. */
final class RateLimiterUnavailable extends \RuntimeException
{
}
