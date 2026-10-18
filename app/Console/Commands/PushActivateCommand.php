<?php

namespace App\Console\Commands;

use App\Services\Push\PushStore;
use Illuminate\Console\Command;

/**
 * فعال‌سازیِ ارسال و ثبتِ مرزِ شروع (یک‌بار): اعلان‌ها و یادآورهایِ قبل از این لحظه هرگز Push نمی‌شوند؛
 * هر رویدادِ بعد از آن، حتی اگر Scheduler دیرتر بالا بیاید یا مدتی متوقف باشد، در اجرایِ بعدی صف می‌شود.
 */
class PushActivateCommand extends Command
{
    protected $signature = 'push:activate
        {--disable : توقفِ ارسال (مرزِ شروع حفظ می‌شود؛ فعال‌سازیِ دوباره عقب‌افتاده‌ها را می‌فرستد)}
        {--reset-boundary : مرزِ شروع را به همین لحظه می‌برد و عقب‌افتاده‌هایِ دورهٔ توقف را کنار می‌گذارد}';

    protected $description = 'فعال/غیرفعال‌سازیِ ارسالِ Web Push و ثبتِ مرزِ شروع';

    public function handle(PushStore $store): int
    {
        $enable = ! $this->option('disable');
        if ($enable && (! config('push.vapid.public_key') || ! config('push.vapid.private_key') || ! config('push.vapid.subject'))) {
            $this->error('ابتدا VAPID_PUBLIC_KEY، VAPID_PRIVATE_KEY و VAPID_SUBJECT را در .env تنظیم کنید (php artisan push:vapid-keys).');

            return self::FAILURE;
        }

        $r = $store->activate($enable, null, (bool) $this->option('reset-boundary'));
        $this->info((string) $r->Message);
        $this->line('ActivatedAt: ' . ($r->ActivatedAt ?? '-') . ' | Watermark: ' . ($r->NotificationWatermarkID ?? '-'));
        if ($enable && ! config('push.enabled')) {
            $this->warn('PUSH_ENABLED در .env خاموش است؛ تا روشن نشود پردازشگر چیزی ارسال نمی‌کند.');
        }

        return self::SUCCESS;
    }
}
