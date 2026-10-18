<?php

namespace App\Console\Commands;

use App\Services\Push\PushDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** هر دقیقه توسطِ Scheduler (سرویسِ scheduler در docker-compose) اجرا می‌شود. */
class PushDispatchCommand extends Command
{
    protected $signature = 'push:dispatch';

    protected $description = 'صف‌بندی و ارسالِ اعلان‌هایِ Web Push از Outbox';

    public function handle(PushDispatcher $dispatcher): int
    {
        try {
            $stats = $dispatcher->run();
        } catch (\Throwable $e) {
            Log::channel('push')->error('push:dispatch failed', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (($stats['status'] ?? '') === 'ok' && ($stats['processed'] > 0 || $stats['queuedNotifications'] > 0 || $stats['queuedReminders'] > 0)) {
            Log::channel('push')->info('push:dispatch', $stats);
        }
        $this->line(json_encode($stats, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
