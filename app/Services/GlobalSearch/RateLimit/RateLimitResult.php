<?php

namespace App\Services\GlobalSearch\RateLimit;

final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        /** شمارهٔ همین درخواست در پنجرهٔ جاری */
        public readonly int $count,
        /** ثانیه تا پایانِ پنجره (برایِ Retry-After) */
        public readonly int $retryAfterSeconds,
    ) {
    }
}
