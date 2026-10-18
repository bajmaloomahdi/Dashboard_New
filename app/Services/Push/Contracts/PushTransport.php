<?php

namespace App\Services\Push\Contracts;

use App\Services\Push\PushResult;

/**
 * ارسالِ یک اعلانِ رمزنگاری‌شده به یک دستگاه. پیاده‌سازیِ واقعی: WebPushTransport (minishlink/web-push)؛
 * در آزمون‌ها با نسخهٔ ساختگی جایگزین می‌شود.
 *
 * @param  array{endpoint: string, p256dh: string, auth: string, contentEncoding: string}  $subscription
 * @param  array{TTL: int, urgency: string, topic: string}  $options
 */
interface PushTransport
{
    public function send(array $subscription, string $payload, array $options): PushResult;
}
