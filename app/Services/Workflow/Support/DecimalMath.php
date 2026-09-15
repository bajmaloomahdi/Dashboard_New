<?php

namespace App\Services\Workflow\Support;

/**
 * مقایسهٔ رشته‌هایِ Decimalِ Canonical بدونِ عبور از float (IEEE-754).
 *
 * از افزونهٔ `bcmath` استفاده می‌کند — این یک افزونهٔ فعالِ PHP (نه یک Composer
 * Package جدید) است؛ `php -m` در همین محیط آن را فعال نشان می‌دهد. اگر روزی در
 * محیطی غیرفعال بود، `bccomp` یک Errorِ واضحِ PHP («Call to undefined function»)
 * می‌دهد، نه نتیجهٔ خاموشِ نادرست.
 */
class DecimalMath
{
    /** آیا رشته دقیقاً با قراردادِ Canonicalِ فاز ۱ (`-?\d+(\.\d+)?`) مطابقت دارد؟ */
    public static function isDecimalString(string $s): bool
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $s) === 1;
    }

    /**
     * @return int  -1 اگر a<b، 0 اگر برابر، 1 اگر a>b — دقیقِ رشته‌ای، بدونِ float.
     */
    public static function compare(string $a, string $b): int
    {
        $scale = max(self::fractionDigits($a), self::fractionDigits($b));

        return bccomp($a, $b, $scale);
    }

    private static function fractionDigits(string $s): int
    {
        $dot = strpos($s, '.');

        return $dot === false ? 0 : strlen($s) - $dot - 1;
    }
}
