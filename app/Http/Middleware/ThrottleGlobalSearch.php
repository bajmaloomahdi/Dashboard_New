<?php

namespace App\Http\Middleware;

use App\Services\GlobalSearch\RateLimit\SearchRateLimiter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * محدودیتِ نرخِ درخواستِ جست‌وجو برایِ هر کاربر (§8 مشخصات). throttleِ بقیهٔ مسیرها تغییری نمی‌کند.
 *
 * پشتیبان: SearchRateLimiter — پیش‌فرض Redis/Garnet با اتصالِ مستقل و شمارندهٔ اتمیک
 * (config: global_search.rate_limit.driver). در آزمون‌هایِ بدونِ Redis، driver = cache با Store = array.
 * fail-closed: اگر پشتیبان در دسترس نباشد، خطا بدهد، Timeout شود یا حافظه‌اش پر باشد، پاسخِ کنترل‌شدهٔ 503
 * برمی‌گردد و جست‌وجو اجرا نمی‌شود — هرگز بدونِ محدودیت ادامه نمی‌دهد. فقط نامِ کلاسِ استثنا لاگ می‌شود.
 */
class ThrottleGlobalSearch
{
    public function __construct(private readonly SearchRateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $maxAttempts = max(1, (int) config('global_search.rate_limit.max_attempts', 20));
        $decaySeconds = max(1, (int) config('global_search.rate_limit.decay_seconds', 10));
        $identity = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        try {
            $result = $this->limiter->attempt('user:' . $identity, $maxAttempts, $decaySeconds);
        } catch (\Throwable $e) {
            Log::warning('global_search.rate_limit_unavailable', ['exception' => $e::class]);

            return response()
                ->json(['message' => 'جست‌وجو موقتاً در دسترس نیست؛ چند لحظه بعد دوباره تلاش کنید.'], 503, [], JSON_UNESCAPED_UNICODE)
                ->header('Retry-After', '5')
                ->header('Cache-Control', 'no-store, private');
        }

        if (! $result->allowed) {
            return response()
                ->json(['message' => 'تعداد درخواست‌های جست‌وجو زیاد است؛ چند لحظه صبر کنید.'], 429)
                ->header('Retry-After', (string) $result->retryAfterSeconds)
                ->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
