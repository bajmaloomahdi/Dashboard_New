<?php

namespace App\Services\GlobalSearch\RateLimit;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

/**
 * محدودیتِ نرخ با کشِ لاراول — فقط برایِ آزمون‌هایِ بدونِ Redis (Store = array).
 * اتمیک نیست (FileStore::increment قفل ندارد)؛ در استقرارِ واقعی باید driver = redis باشد.
 */
class CacheSearchRateLimiter implements SearchRateLimiter
{
    public function __construct(private readonly string $store)
    {
    }

    public function attempt(string $userKey, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        $limiter = new RateLimiter(Cache::store($this->store));
        $key = 'global-search:' . sha1($userKey);

        if ($limiter->tooManyAttempts($key, max(1, $maxAttempts))) {
            return new RateLimitResult(false, $limiter->attempts($key), max(1, $limiter->availableIn($key)));
        }
        $count = $limiter->hit($key, max(1, $decaySeconds));

        return new RateLimitResult(true, $count, max(1, $decaySeconds));
    }
}
