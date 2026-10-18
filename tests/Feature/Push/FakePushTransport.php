<?php

namespace Tests\Feature\Push;

use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushResult;

/**
 * Transportِ ساختگیِ آزمون‌ها: هیچ درخواستِ شبکه‌ای نمی‌فرستد؛ هر ارسال را ثبت می‌کند و برایِ هر Endpoint
 * نتیجهٔ برنامه‌ریزی‌شده (صفی از PushResult) یا به‌طورِ پیش‌فرض «پذیرفته‌شده (201)» برمی‌گرداند.
 */
class FakePushTransport implements PushTransport
{
    /** @var array<int, array{endpoint: string, payload: array, options: array}> */
    public array $sent = [];

    /** @var array<string, PushResult[]> */
    private array $plans = [];

    public function plan(string $endpoint, PushResult ...$results): self
    {
        $this->plans[$endpoint] = array_merge($this->plans[$endpoint] ?? [], $results);

        return $this;
    }

    public function send(array $subscription, string $payload, array $options): PushResult
    {
        $this->sent[] = [
            'endpoint' => $subscription['endpoint'],
            'payload' => json_decode($payload, true) ?? [],
            'rawPayload' => $payload,
            'options' => $options,
        ];
        $queue = $this->plans[$subscription['endpoint']] ?? [];
        if ($queue) {
            $result = array_shift($queue);
            $this->plans[$subscription['endpoint']] = $queue;

            return $result;
        }

        return new PushResult(PushResult::SENT, 201);
    }

    public function sentTo(string $endpoint): array
    {
        return array_values(array_filter($this->sent, fn ($s) => $s['endpoint'] === $endpoint));
    }
}
