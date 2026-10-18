<?php

namespace App\Services\Push;

/**
 * محتوایِ اعلان. عمداً عمومی و بدونِ اطلاعاتِ حساس است (بدونِ موضوع، فرستنده یا عنوانِ یادآوری)
 * چون رویِ صفحهٔ قفل و حتی پس از خروجِ کاربر نمایش داده می‌شود؛ جزئیات فقط پس از ورود و بررسیِ دسترسی در سرور.
 * استثنا: اعلانِ عمومی (BROADCAST) عنوان/متنی است که مدیر برایِ همه نوشته و همان نمایش داده می‌شود.
 */
class PushPayloadFactory
{
    private const TITLE = 'راگا ۳۶۰';

    private const BODIES = [
        'MESSAGE' => 'پیام یا تغییرِ تازه‌ای در کارتابلِ شما هست.',
        'TASK' => 'وظیفه یا ارجاعِ تازه‌ای در کارتابلِ شما هست.',
        'REMINDER' => 'یک یادآوریِ سررسیدشده دارید.',
        'TEST' => 'اعلانِ آزمایشی: اعلان‌هایِ این دستگاه کار می‌کند.',
    ];

    /** حداکثر اندازهٔ متنِ JSON (بایت) — با ۶۰+۲۴۰ نویسهٔ فارسی هم بسیار کمتر از سقفِ ~۴ کیلوبایتِ Web Push */
    public const MAX_BYTES = 2048;

    /** @param  object|null  $broadcast  برایِ BROADCAST: {Title, Body} */
    public function make(object $outbox, ?object $broadcast = null): string
    {
        $kind = (string) $outbox->Kind;
        $title = self::TITLE;
        $body = self::BODIES[$kind] ?? self::BODIES['MESSAGE'];
        if ($kind === 'BROADCAST') {
            if (! $broadcast) {
                throw new \RuntimeException('محتوایِ اعلانِ عمومی پیدا نشد.');
            }
            $title = PushBroadcastText::title($broadcast->Title) ?: self::TITLE;
            $body = PushBroadcastText::body($broadcast->Body);
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
    public function options(object $outbox): array
    {
        $kind = (string) $outbox->Kind;
        $ttl = (array) config('push.ttl_seconds', []);

        return [
            'TTL' => (int) ($ttl[$kind] ?? 86400),
            'urgency' => $kind === 'REMINDER' ? 'high' : 'normal',
            'topic' => self::topic((string) $outbox->Tag),
        ];
    }

    /**
     * Topic: فقط حروف و ارقامِ لاتین، حداکثر ۳۲ نویسه. RFC 8030 «-» و «_» را هم مجاز می‌داند، اما سرویسِ Pushِ اپل
     * (iPhone/iPad/Safari) هر Topicی را که «-» داشته باشد با 400 BadWebPushTopic رد می‌کند (Google می‌پذیرد).
     * جداکنندهٔ «-»ِ Tag به «x» تبدیل می‌شود (شناسه‌ها عددی‌اند، پس یکتایی حفظ می‌شود)؛ Tagِ بلندتر → MD5 (۳۲ نویسهٔ hex).
     */
    public static function topic(string $tag): string
    {
        $t = preg_replace('/[^A-Za-z0-9]/', 'x', $tag);

        return strlen($t) <= 32 && $t !== '' ? $t : md5($tag);
    }
}
