<?php

namespace App\Console\Commands;

use App\Services\Push\PushStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** پاک‌سازیِ روزانهٔ سوابقِ پایان‌یافتهٔ Outbox (موارد در انتظار/در حالِ ارسال هرگز حذف نمی‌شوند). */
class PushCleanupCommand extends Command
{
    protected $signature = 'push:cleanup {--days= : مدتِ نگه‌داری (پیش‌فرض: PUSH_RETENTION_DAYS)}';

    protected $description = 'پاک‌سازیِ سوابقِ قدیمیِ ارسالِ Web Push';

    public function handle(PushStore $store): int
    {
        $days = (int) ($this->option('days') ?: config('push.retention_days', 30));
        $r = $store->cleanup($days);
        Log::channel('push')->info('push:cleanup', ['removed' => (int) ($r->Removed ?? 0), 'days' => $days]);
        $this->info("حذف‌شده: " . (int) ($r->Removed ?? 0));

        return self::SUCCESS;
    }
}
