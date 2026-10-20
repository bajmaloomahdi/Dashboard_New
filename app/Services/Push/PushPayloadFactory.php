<?php

namespace App\Services\Push;

use App\Services\Calendar\ReminderContent;

/**
 * محتوایِ اعلان. عمداً عمومی و بدونِ اطلاعاتِ حساس است (بدونِ موضوع، فرستنده یا عنوانِ یادآوری)
 * چون رویِ صفحهٔ قفل و حتی پس از خروجِ کاربر نمایش داده می‌شود؛ جزئیات فقط پس از ورود و بررسیِ دسترسی در سرور.
 * استثنا: اعلانِ عمومی (BROADCAST) عنوان/متنی است که مدیر برایِ همه نوشته و همان نمایش داده می‌شود.
 * استثنا: یادآوری موضوع و متنِ کوتاه‌شدهٔ خودِ یادآوری را (فقط برایِ صاحبش) نشان می‌دهد — با PUSH_REMINDER_DETAILS=false
 * دوباره عمومی می‌شود؛ رویدادِ خصوصی همیشه عمومی می‌ماند.
 */
class PushPayloadFactory
{
    private const TITLE = 'راگا ۳۶۰';

    private const BODIES = [
        'MESSAGE' => 'پیام یا تغییرِ تازه‌ای در کارتابلِ شما هست.',
        'TASK' => 'وظیفه یا ارجاعِ تازه‌ای در کارتابلِ شما هست.',
        'REMINDER' => 'یک یادآوریِ سررسیدشده دارید.',
        'REMINDER_PRIVATE' => 'یادآوریِ یک رویدادِ خصوصی دارید.',
        'INVITE' => 'دعوت به جلسهٔ تازه‌ای دارید.',
        // دعوتِ دوباره به‌خاطرِ تغییرِ زمان/تکرار یا برگرداندن از لغو (Tagِ «invu-» از sp_Push_Enqueue) — بدونِ عنوانِ جلسه
        'INVITE_UPDATE' => 'یکی از جلسه‌هایتان تغییر کرد؛ لطفاً دوباره پاسخ دهید.',
        'CANCEL' => 'یکی از جلسه‌هایتان لغو شد.', // لغو یا حذفِ جلسه (پچِ ۱۱۳) — بدونِ عنوان
        'TEST' => 'اعلانِ آزمایشی: اعلان‌هایِ این دستگاه کار می‌کند.',
    ];

    /** حداکثر اندازهٔ متنِ JSON (بایت) — با ۶۰+۲۴۰ نویسهٔ فارسی هم بسیار کمتر از سقفِ ~۴ کیلوبایتِ Web Push */
    public const MAX_BYTES = 2048;

    /**
     * @param  object|null  $broadcast  برایِ BROADCAST: {Title, Body}
     * @param  array|null  $reminder  برایِ REMINDER: خروجیِ ReminderContent::for (null = متنِ عمومی)
     */
    public function make(object $outbox, ?object $broadcast = null, ?array $reminder = null): string
    {
        $kind = (string) $outbox->Kind;
        $title = self::TITLE;
        $body = self::BODIES[$kind] ?? self::BODIES['MESSAGE'];
        if ($kind === 'INVITE' && str_starts_with((string) $outbox->Tag, 'invu-')) {
            $body = self::BODIES['INVITE_UPDATE'];
        }
        if ($kind === 'BROADCAST') {
            if (! $broadcast) {
                throw new \RuntimeException('محتوایِ اعلانِ عمومی پیدا نشد.');
            }
            $title = PushBroadcastText::title($broadcast->Title) ?: self::TITLE;
            $body = PushBroadcastText::body($broadcast->Body);
        }
        if ($kind === 'REMINDER' && $reminder !== null) {
            if ($reminder['isPrivate']) {
                $body = self::BODIES['REMINDER_PRIVATE']; // رویدادِ خصوصی: عنوان/متن رویِ صفحهٔ قفل نمی‌آید
            } elseif ($reminder['title'] !== '') {
                $title = 'یادآوری: ' . ReminderContent::truncate($reminder['title'], ReminderContent::TITLE_MAX);
                $body = $reminder['description'] !== null
                    ? ReminderContent::truncate($reminder['description'], ReminderContent::BODY_MAX)
                    : ($reminder['startsAt'] !== null ? 'شروع: ' . ReminderContent::jalaliDateTime($reminder['startsAt']) : self::BODIES['REMINDER']);
            }
        }
        $url = (string) $outbox->TargetUrl;
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            $url = '/dashboard'; // فقط مسیرِ نسبیِ همین برنامه
        }

        $payload = json_encode([
            'v' => 1,
            'id' => (string) $outbox->PushOutboxID,
            'tag' => (string) $outbox->Tag,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false || strlen($payload) > self::MAX_BYTES) {
            throw new \RuntimeException('محتوایِ اعلان از سقفِ مجاز بزرگ‌تر است.');
        }

        return $payload;
    }

    /** گزینه‌هایِ پروتکل: TTL، اولویت و Topic (یک اعلانِ تحویل‌نشده با Topicِ یکسان جایگزین می‌شود) */
    public function options(object $outbox, ?int $expiresInSeconds = null): array
    {
        $kind = (string) $outbox->Kind;
        $ttl = (array) config('push.ttl_seconds', []);
        $seconds = (int) ($ttl[$kind] ?? 86400);
        if ($expiresInSeconds !== null) {
            // پچِ ۱۱۳: اعلان پس از پایانِ جلسه به دستگاه نرسد (حداقل ۶۰ ثانیه برایِ تحویل)
            $seconds = min($seconds, max(60, $expiresInSeconds));
        }

        return [
            'TTL' => $seconds,
            'urgency' => $kind === 'REMINDER' ? 'high' : 'normal',
            'topic' => self::topic((string) $outbox->Tag),
        ];
    }

    /**
     * Topic: همیشه MD5ِ Tag (دقیقاً ۳۲ نویسهٔ hex). RFC 8030 تا ۳۲ نویسهٔ base64url (با «-» و «_») را مجاز می‌داند، اما سرویسِ
     * Pushِ اپل (iPhone/iPad/Safari) سخت‌گیرتر است و با 400 BadWebPushTopic رد کرد: هر Topicِ دارایِ «-» (مثلِ msg-1)
     * و Topicِ کوتاه (bcx61، ۵ نویسه)؛ ۶ نویسه به بالا و MD5ِ ۳۲نویسه‌ای پذیرفته شد (پاسخِ واقعیِ web.push.apple.com، ۲۰۲۶-۱۰-۱۸).
     * طولِ ثابت و فقط حروف/ارقام هر دو قاعده را پوشش می‌دهد؛ Tagِ یکسان → Topicِ یکسان (جایگزینیِ اعلانِ تحویل‌نشده حفظ می‌شود).
     */
    public static function topic(string $tag): string
    {
        return md5($tag);
    }
}
