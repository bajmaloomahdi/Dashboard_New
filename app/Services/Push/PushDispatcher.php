<?php

namespace App\Services\Push;

use App\Services\Push\Contracts\PushTransport;
use Illuminate\Support\Facades\Log;

/**
 * پردازشگرِ دقیقه‌ایِ Outbox: صف‌بندی → Claimِ اتمیک → ارسال به هر دستگاه → ثبتِ نتیجه.
 *
 * تحویل «حداقل یک‌بار» است: اگر پردازش بعد از پذیرشِ اعلان توسطِ سرویسِ Push و قبل از ثبتِ نتیجه متوقف شود،
 * ردیفِ ارسال در وضعیتِ SENDING می‌ماند و پس از انقضایِ Lease دوباره ارسال می‌شود. Tagِ ثابت و کنترلِ تکرار در
 * Service Worker اعلانِ تکراری را در دستگاه جایگزین/حذف می‌کند؛ Exactly-once تضمین نمی‌شود.
 */
class PushDispatcher
{
    public function __construct(
        private PushStore $store,
        private PushTransport $transport,
        private PushPayloadFactory $payloads,
    ) {
    }

    public static function workerName(): string
    {
        return mb_substr((gethostname() ?: 'host') . ':' . getmypid() . ':' . bin2hex(random_bytes(3)), 0, 100);
    }

    /** @return array<string, int|string> آمارِ همین اجرا */
    public function run(?string $worker = null): array
    {
        $worker ??= self::workerName();
        $stats = ['queuedNotifications' => 0, 'queuedReminders' => 0, 'processed' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => 0];

        $state = $this->store->heartbeat($worker);
        if (! config('push.enabled')) {
            return ['status' => 'disabled-config'] + $stats;
        }
        if ((int) ($state->IsEnabled ?? 0) !== 1 || empty($state->ActivatedAt)) {
            return ['status' => 'not-activated'] + $stats;
        }

        $q = $this->store->enqueue(config('push.max_age_minutes'));
        $stats['queuedNotifications'] = (int) ($q->NotificationsQueued ?? 0);
        $stats['queuedReminders'] = (int) ($q->RemindersQueued ?? 0);

        $deadline = microtime(true) + (int) config('push.run_budget_seconds', 50);
        while (microtime(true) < $deadline) {
            $batch = $this->store->claimBatch($worker, (int) config('push.batch_size', 50), (int) config('push.lease_seconds', 300));
            if (! $batch) {
                break;
            }
            foreach ($batch as $row) {
                $this->processOne($row, $worker, $stats);
                $stats['processed']++;
            }
        }

        return ['status' => 'ok'] + $stats;
    }

    private function processOne(object $row, string $worker, array &$stats): void
    {
        $id = (int) $row->PushOutboxID;
        $validity = $this->store->checkOutbox($id, $worker);
        if ($validity === 'LEASE_LOST') {
            Log::channel('push')->warning('push: lease lost before send', ['outbox' => $id]);

            return;
        }
        if ($validity !== 'OK') {
            $this->store->completeOutbox($id, $worker, $validity);
            $stats['skipped']++;

            return;
        }

        $targets = $this->store->targets($id, $worker);
        if (! $targets) {
            // اولین تلاش بدونِ دستگاه = کاربر اشتراک ندارد؛ تلاش‌هایِ بعدی = همهٔ دستگاه‌ها قبلاً تعیین‌تکلیف شده‌اند
            $this->store->completeOutbox($id, $worker, (int) $row->Attempts <= 1 ? 'SKIPPED_NO_SUBSCRIPTION' : 'DONE');
            $stats['skipped']++;

            return;
        }

        $broadcast = null;
        if ((string) $row->SourceType === 'BROADCAST') {
            $broadcast = $this->store->broadcastContent((int) $row->SourceID);
            if (! $broadcast) {
                $this->store->completeOutbox($id, $worker, 'SKIPPED_INVALID');
                $stats['skipped']++;

                return;
            }
        }

        $validator = PushEndpointValidator::fromConfig();
        $payload = $this->payloads->make($row, $broadcast);
        $options = $this->payloads->options($row);
        $retry = false;
        $retryAfter = null;
        $lastError = null;

        foreach ($targets as $t) {
            $subId = (int) $t->PushSubscriptionID;
            if (! $this->store->beginDelivery($id, $subId, $worker)) {
                continue; // Lease از دست رفته یا این دستگاه قبلاً تعیین‌تکلیف شده
            }
            if (! $validator->isAllowed($t->Endpoint)) {
                $this->store->recordDelivery($id, $subId, $worker, PushResult::FAILED, null, 'endpoint host not allowed');
                $this->store->disableSubscription($subId, 'INVALID_ENDPOINT');
                $stats['failed']++;
                continue;
            }

            $result = $this->transport->send([
                'endpoint' => (string) $t->Endpoint,
                'p256dh' => (string) $t->P256dh,
                'auth' => (string) $t->AuthSecret,
                'contentEncoding' => (string) $t->ContentEncoding,
            ], $payload, $options);

            $this->store->recordDelivery($id, $subId, $worker, $result->status, $result->httpStatus, $result->error);
            $stats[strtolower($result->status)]++;

            if ($result->status === PushResult::RETRY) {
                $retry = true;
                $lastError = $result->error;
                if ($result->retryAfterSeconds !== null) {
                    $retryAfter = max($retryAfter ?? 0, $result->retryAfterSeconds);
                }
            } elseif ($result->status !== PushResult::SENT) {
                Log::channel('push')->info('push: delivery not accepted', ['outbox' => $id, 'subscription' => $subId, 'status' => $result->status, 'http' => $result->httpStatus]);
            }
        }

        if ($retry) {
            $attempts = (int) $row->Attempts;
            if ($attempts >= (int) config('push.max_attempts', 5)) {
                $this->store->completeOutbox($id, $worker, 'DONE', null, 'max attempts reached: ' . $lastError);

                return;
            }
            $this->store->completeOutbox($id, $worker, 'RETRY', $this->retryDelay($attempts, $retryAfter), $lastError);

            return;
        }

        $this->store->completeOutbox($id, $worker, 'DONE');
    }

    /** فاصلهٔ افزایشی (۱، ۲، ۴، … دقیقه، حداکثر یک ساعت)؛ Retry-Afterِ سرویس اگر بیشتر باشد رعایت می‌شود */
    public function retryDelay(int $attempts, ?int $retryAfter): int
    {
        $base = (int) config('push.backoff_base_seconds', 60);
        $max = (int) config('push.backoff_max_seconds', 3600);
        $delay = min($max, $base * (2 ** max(0, $attempts - 1)));

        return max($delay, min($retryAfter ?? 0, 86400));
    }
}
