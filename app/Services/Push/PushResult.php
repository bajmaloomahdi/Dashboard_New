<?php

namespace App\Services\Push;

/**
 * نتیجهٔ ارسال به یک دستگاه:
 * SENT (پذیرش توسطِ سرویسِ Push — نه مشاهدهٔ کاربر)، RETRY (موقت: Timeout/شبکه، 429، 5xx)،
 * EXPIRED (404/410 — اشتراک غیرفعال می‌شود)، FAILED (خطایِ دائمی برایِ همین اعلان).
 */
final class PushResult
{
    public const SENT = 'SENT';
    public const RETRY = 'RETRY';
    public const EXPIRED = 'EXPIRED';
    public const FAILED = 'FAILED';

    public function __construct(
        public readonly string $status,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $error = null,
    ) {
    }

    /** طبقه‌بندیِ پاسخِ HTTP (null = بدونِ پاسخ: خطایِ شبکه/Timeout) */
    public static function fromHttp(?int $httpStatus, ?int $retryAfterSeconds = null, ?string $error = null): self
    {
        if ($httpStatus === null) {
            return new self(self::RETRY, null, $retryAfterSeconds, $error ?: 'network error');
        }
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return new self(self::SENT, $httpStatus);
        }
        if ($httpStatus === 404 || $httpStatus === 410) {
            return new self(self::EXPIRED, $httpStatus, null, $error);
        }
        if ($httpStatus === 429 || $httpStatus >= 500) {
            return new self(self::RETRY, $httpStatus, $retryAfterSeconds, $error);
        }

        return new self(self::FAILED, $httpStatus, null, $error);
    }

    /** Retry-After: ثانیه یا تاریخِ HTTP */
    public static function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        $header = trim($header);
        if (ctype_digit($header)) {
            return (int) $header;
        }
        $ts = strtotime($header);

        return $ts === false ? null : max(0, $ts - time());
    }
}
