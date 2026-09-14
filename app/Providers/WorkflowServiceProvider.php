<?php

namespace App\Providers;

use App\Services\BusinessCalendar\BusinessCalendarService;
use App\Services\BusinessCalendar\DbBusinessCalendarService;
use App\Services\Workflow\Entity\EntityResolverRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * ثبتِ سرویس‌های ماژولِ فرایند و ماژولِ تقویمِ کاری.
 *
 * فقط bindingهای افزایشی است؛ هیچ سرویسِ موجودی را بازتعریف نمی‌کند.
 */
class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ماژولِ مستقلِ تقویمِ کاری
        $this->app->singleton(BusinessCalendarService::class, DbBusinessCalendarService::class);

        // رجیستریِ EntityResolverها بر پایهٔ config/workflow.php
        $this->app->singleton(EntityResolverRegistry::class, function ($app) {
            return new EntityResolverRegistry(
                $app,
                (array) config('workflow.entities', [])
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
