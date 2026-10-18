<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // جست‌وجویِ سراسری — منبعِ مجوزها (sp_GetUserPermissions)؛ در آزمون‌ها با نسخهٔ ساختگی جایگزین می‌شود
        $this->app->bind(
            \App\Services\GlobalSearch\Contracts\AccessContextResolver::class,
            \App\Services\GlobalSearch\Support\SqlAccessContextResolver::class
        );

        // Web Push — ارسالِ واقعی با minishlink/web-push؛ در آزمون‌ها با Transportِ ساختگی جایگزین می‌شود
        $this->app->singleton(
            \App\Services\Push\Contracts\PushTransport::class,
            \App\Services\Push\WebPushTransport::class
        );

        // جست‌وجویِ سراسری — محدودیتِ نرخ: redis (پیش‌فرض، اتمیک، fail-closed) یا cache (فقط آزمون، Store = array).
        // هر مقدارِ ناشناخته هم Redis در نظر گرفته می‌شود تا محدودیت هرگز بی‌صدا ضعیف نشود.
        $this->app->bind(\App\Services\GlobalSearch\RateLimit\SearchRateLimiter::class, function () {
            if (config('global_search.rate_limit.driver') === 'cache') {
                return new \App\Services\GlobalSearch\RateLimit\CacheSearchRateLimiter(
                    (string) config('global_search.rate_limit.store', 'array')
                );
            }

            return new \App\Services\GlobalSearch\RateLimit\RedisSearchRateLimiter(
                (array) config('global_search.rate_limit.redis', [])
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // عنوان صفحه (کنار فاوآیکون + نام هنگام افزودن به صفحه اصلی موبایل)
        // را برای استفاده در app.blade.php در اختیار همه‌ی ویوها می‌گذاریم.
        View::composer('app', function ($view) {
            $siteTitle = 'پنل مدیریت';
            try {
                $company = DB::select('EXEC sp_GetCompany')[0] ?? null;
                if ($company && !empty($company->SiteTitle)) {
                    $siteTitle = $company->SiteTitle;
                }
            } catch (\Throwable $e) {
                // اگر دیتابیس در دسترس نبود (مثلاً هنگام build)، مقدار پیش‌فرض استفاده می‌شود
            }

            $view->with('siteTitle', $siteTitle);
        });
    }
}