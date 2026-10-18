<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * تولیدِ کلیدهایِ VAPIDِ اختصاصیِ همین نصب. کلیدِ خصوصی فقط در .env نوشته می‌شود (وارد Git نمی‌شود) و چاپ نمی‌شود.
 * اگر کلید از قبل وجود دارد، عوض نمی‌شود — تعویضِ کلید همهٔ اشتراک‌هایِ دستگاه‌ها را بی‌اعتبار می‌کند.
 */
class PushVapidKeysCommand extends Command
{
    protected $signature = 'push:vapid-keys
        {--subject= : mailto:... یا https://... (برایِ VAPID_SUBJECT)}
        {--force : جایگزینیِ کلیدهایِ موجود (همهٔ اشتراک‌ها از کار می‌افتند)}';

    protected $description = 'تولید و ذخیرهٔ کلیدهایِ VAPID در .env';

    public function handle(): int
    {
        $envPath = base_path('.env');
        if (! is_file($envPath) || ! is_writable($envPath)) {
            $this->error('.env پیدا نشد یا قابلِ نوشتن نیست.');

            return self::FAILURE;
        }
        $env = (string) file_get_contents($envPath);
        $has = (bool) preg_match('/^VAPID_PRIVATE_KEY=\S+/m', $env);
        if ($has && ! $this->option('force')) {
            $this->info('کلیدهایِ VAPID از قبل تنظیم شده‌اند و تغییر نکردند.');

            return self::SUCCESS;
        }

        $subject = (string) ($this->option('subject') ?: config('push.vapid.subject') ?: '');
        if (! preg_match('#^(mailto:.+@.+|https://.+)$#', $subject)) {
            $this->error('--subject معتبر لازم است (mailto:admin@example.com یا https://example.com).');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();
        $values = ['VAPID_SUBJECT' => $subject, 'VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']];
        foreach ($values as $name => $value) {
            $line = $name . '=' . $value;
            $env = preg_match('/^' . $name . '=.*$/m', $env)
                ? preg_replace('/^' . $name . '=.*$/m', $line, $env)
                : rtrim($env, "\r\n") . PHP_EOL . $line . PHP_EOL;
        }
        file_put_contents($envPath, $env);

        $this->info('کلیدهایِ VAPID در .env ذخیره شد (کلیدِ خصوصی نمایش داده نمی‌شود؛ از .env نسخهٔ پشتیبان بگیرید).');
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);

        return self::SUCCESS;
    }
}
