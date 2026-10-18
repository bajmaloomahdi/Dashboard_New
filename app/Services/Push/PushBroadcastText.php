<?php

namespace App\Services\Push;

/**
 * پاک‌سازیِ عنوان/متنِ اعلانِ عمومی. اعلانِ سیستم‌عامل HTML را تفسیر نمی‌کند، ولی همین متن در صفحهٔ مدیریت و
 * سوابق هم نمایش داده می‌شود؛ پس تگ‌ها، نویسه‌هایِ کنترلی و نویسه‌هایِ جهت‌دهیِ صریحِ (Bidi override) که متن را
 * وارونه/جعلی نشان می‌دهند حذف می‌شوند. نیم‌فاصله (ZWNJ) و ZWJ برایِ فارسی حفظ می‌شوند.
 */
class PushBroadcastText
{
    public const TITLE_MAX = 60;

    public const BODY_MAX = 240;

    /** طول به واحدِ UTF-16 (همان شمارشِ NVARCHARِ SQL Server و string.lengthِ مرورگر) */
    public static function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function title(?string $text): string
    {
        // عنوان یک‌خطی است
        return self::clean(preg_replace('/\s+/u', ' ', (string) $text));
    }

    public static function body(?string $text): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
        $t = preg_replace("/ *\n */u", "\n", $t);
        $t = preg_replace("/\n{3,}/u", "\n\n", $t); // حداکثر یک خطِ خالی

        return self::clean($t);
    }

    private static function clean(?string $t): string
    {
        $t = (string) $t;
        if (! mb_check_encoding($t, 'UTF-8')) {
            $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
        }
        // تگ‌ها و توضیحاتِ HTML حذف می‌شوند؛ «<» تنها (مثلِ «کمتر از <۵») متنِ عادی است و می‌ماند
        $t = preg_replace('/<!--.*?(-->|$)/su', '', $t);
        $t = preg_replace('/<[\/!?]?[A-Za-z][^<>]*(>|$)/u', '', $t);
        // نویسه‌هایِ کنترلی (به‌جز \n)، Bidi override/isolate، و نویسه‌هایِ نامرئیِ دیگر — ZWNJ (200C) و ZWJ (200D) می‌مانند
        $t = preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}]/u', '', $t);

        return trim((string) $t);
    }
}
