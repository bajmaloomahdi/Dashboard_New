<?php

namespace App\Services\Push;

use App\Services\Push\Contracts\PushTransport;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * ارسالِ واقعی با minishlink/web-push (VAPID + رمزنگاریِ aes128gcm).
 * کلاینتِ HTTP بدونِ دنبال‌کردنِ Redirect و با Timeout ساخته می‌شود تا مقصد هرگز از میزبانِ مجاز خارج نشود (SSRF).
 */
class WebPushTransport implements PushTransport
{
    private ?WebPush $webPush = null;

    private function client(): WebPush
    {
        if ($this->webPush) {
            return $this->webPush;
        }
        $vapid = (array) config('push.vapid');
        if (empty($vapid['public_key']) || empty($vapid['private_key']) || empty($vapid['subject'])) {
            throw new \RuntimeException('کلیدهایِ VAPID تنظیم نشده‌اند (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT).');
        }

        $http = new Client([
            'timeout' => (int) config('push.http_timeout_seconds', 10),
            'connect_timeout' => (int) config('push.http_connect_timeout_seconds', 5),
            'allow_redirects' => false,
            'http_errors' => false,
        ]);

        $this->webPush = new WebPush(
            ['VAPID' => ['subject' => $vapid['subject'], 'publicKey' => $vapid['public_key'], 'privateKey' => $vapid['private_key']]],
            [],
            $http
        );
        $this->webPush->setReuseVAPIDHeaders(true);

        return $this->webPush;
    }

    public function send(array $subscription, string $payload, array $options): PushResult
    {
        try {
            $report = $this->client()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription['endpoint'],
                    'publicKey' => $subscription['p256dh'],
                    'authToken' => $subscription['auth'],
                    'contentEncoding' => $subscription['contentEncoding'] ?: 'aes128gcm',
                ]),
                $payload,
                $options
            );
        } catch (\Throwable $e) {
            // خطایِ رمزنگاری/کلید (نه شبکه) — برایِ همین اعلان دائمی است
            return new PushResult(PushResult::FAILED, null, null, mb_substr($e->getMessage(), 0, 500));
        }

        $response = $report->getResponse();
        if ($response === null) {
            return PushResult::fromHttp(null, null, mb_substr($report->getReason(), 0, 500));
        }

        return PushResult::fromHttp(
            $response->getStatusCode(),
            PushResult::parseRetryAfter($response->getHeaderLine('Retry-After') ?: null),
            $report->isSuccess() ? null : self::errorText($report->getReason(), $response)
        );
    }

    /**
     * علتِ واقعیِ ردشدن از بدنهٔ پاسخ (مثلاً اپل: {"reason":"BadWebPushTopic"}) — عنوانِ HTTP مثلِ «Bad Request» به‌تنهایی
     * برایِ تشخیص کافی نیست. فقط متنِ کوتاهِ خطایِ سرویس ذخیره می‌شود؛ Endpoint/کلیدها هرگز.
     */
    private static function errorText(string $reason, \Psr\Http\Message\ResponseInterface $response): string
    {
        try {
            $body = trim(mb_substr((string) $response->getBody(), 0, 300));
        } catch (\Throwable) {
            $body = '';
        }
        $json = $body !== '' ? json_decode($body, true) : null;
        $detail = is_array($json) ? (string) ($json['reason'] ?? $json['message'] ?? $json['error']['message'] ?? $body) : $body;
        $detail = preg_replace('/\s+/u', ' ', strip_tags($detail));

        return mb_substr($detail !== '' && ! str_contains($reason, $detail) ? "{$reason}: {$detail}" : $reason, 0, 500);
    }
}
