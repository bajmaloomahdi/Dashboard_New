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
        //
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