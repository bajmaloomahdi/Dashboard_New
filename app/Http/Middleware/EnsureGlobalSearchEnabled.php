<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature Flag جست‌وجویِ سراسری: وقتی خاموش است مسیر وجود ندارد (404) — §3 مشخصات.
 * در زمانِ اجرا بررسی می‌شود تا با route:cache هم تغییرِ فلگ بدونِ بازسازیِ مسیرها اعمال شود.
 */
class EnsureGlobalSearchEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('global_search.enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
