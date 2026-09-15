<?php

namespace App\Services\Workflow\Support;

/**
 * Validate/Cast مقادیر per DataType — کاملاً Machine-canonical و مستقل از Locale
 * (فارسی/انگلیسی، جداکنندهٔ هزارگان، تقویمِ جلالی هیچ‌کدام اینجا راه ندارند؛
 * نرمال‌سازیِ ورودیِ کاربر مسئولیتِ لایهٔ فراخوان است، نه این کلاس).
 *
 * هم برایِ Castِ Contextِ Start (ConditionContextBuilder) و هم برایِ Castِ مقدارِ
 * CONSTANT در RuleJson (ConditionRuleValidator) استفاده می‌شود — یک منبعِ واحد از قواعد.
 */
class ConditionDataTypeCaster
{
    /**
     * @param  string[]|null  $allowedValues  فقط برایِ DataType=SELECT
     * @return array{ok:bool, value:mixed, error:?string}
     */
    public static function cast(string $dataType, mixed $raw, ?array $allowedValues = null): array
    {
        if ($raw === null) {
            return ['ok' => true, 'value' => null, 'error' => null];
        }

        return match ($dataType) {
            'INTEGER' => self::castInteger($raw),
            'DECIMAL' => self::castDecimal($raw),
            'DATE'    => self::castDate($raw),
            'BOOLEAN' => self::castBoolean($raw),
            'STRING'  => self::castString($raw),
            'SELECT'  => self::castSelect($raw, $allowedValues ?? []),
            'USER', 'UNIT' => self::castReferenceId($raw),
            default   => self::fail("نوعِ دادهٔ «{$dataType}» شناخته‌شده نیست."),
        };
    }

    /**
     * سرریز قبل از Cast رد می‌شود: `filter_var(..., FILTER_VALIDATE_INT)` یک عددِ
     * خارج از بازهٔ PHP_INT_MIN..PHP_INT_MAX را `false` برمی‌گرداند (نه یک برشِ
     * خاموش) — پس هرگز به `(int)` روی رشتهٔ خیلی بزرگ متکی نمی‌شویم.
     */
    private static function castInteger(mixed $raw): array
    {
        if (is_int($raw)) {
            return self::ok($raw);
        }
        if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            $filtered = filter_var($raw, FILTER_VALIDATE_INT);
            if ($filtered === false) {
                return self::fail('مقدار از محدودهٔ عددیِ صحیحِ مجاز خارج است.');
            }

            return self::ok($filtered);
        }

        return self::fail('مقدار باید یک عددِ صحیح باشد.');
    }

    /**
     * DECIMAL هرگز از float عبور نمی‌کند: ورودی باید یک رشتهٔ JSON با الگویِ دقیقِ
     * `-?\d+(\.\d+)?` باشد (نه عددِ JSON — چون یک عددِ JSON تا رسیدن به اینجا از قبل
     * توسطِ json_decode به float تبدیل شده و دقتش از دست رفته است). خروجی همیشه یک
     * رشتهٔ Canonical است: بدونِ صفرهایِ اضافیِ ابتدا/انتها، بدونِ «-0».
     */
    private static function castDecimal(mixed $raw): array
    {
        if (! is_string($raw) || preg_match('/^-?\d+(\.\d+)?$/', $raw) !== 1) {
            return self::fail('مقدار باید یک رشتهٔ Decimalِ استاندارد باشد (نقطهٔ اعشار، بدونِ جداکنندهٔ هزارگان/رقمِ فارسی/Scientific Notation، به‌صورتِ رشتهٔ JSON نه عددِ JSON).');
        }

        return self::ok(self::canonicalizeDecimal($raw));
    }

    private static function canonicalizeDecimal(string $s): string
    {
        $sign = '';
        if ($s[0] === '-') {
            $sign = '-';
            $s = substr($s, 1);
        }

        [$intPart, $fracPart] = array_pad(explode('.', $s, 2), 2, '');

        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }

        $fracPart = rtrim($fracPart, '0');

        $canonical = $intPart . ($fracPart !== '' ? '.' . $fracPart : '');

        if ($canonical === '0') {
            $sign = ''; // منفیِ صفر معنا ندارد
        }

        return $sign . $canonical;
    }

    /** فرمتِ ذخیره: ISO-8601 «YYYY-MM-DD» میلادی — بدونِ زمان، بدونِ Timezone، بدونِ جلالی. */
    private static function castDate(mixed $raw): array
    {
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return self::fail('تاریخ باید به‌فرمتِ ISO-8601 میلادی «YYYY-MM-DD» باشد.');
        }

        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        if (! checkdate($m, $d, $y)) {
            return self::fail('تاریخِ واردشده معتبر نیست.');
        }

        return self::ok($raw);
    }

    private static function castBoolean(mixed $raw): array
    {
        if (is_bool($raw)) {
            return self::ok($raw);
        }

        return self::fail('مقدار باید یک بولیِ صریح (true/false) باشد.');
    }

    private static function castString(mixed $raw): array
    {
        if (! is_string($raw)) {
            return self::fail('مقدار باید رشته باشد.');
        }
        if (mb_strlen($raw) > 500) {
            return self::fail('طولِ رشته نباید از ۵۰۰ کاراکتر بیشتر باشد.');
        }

        return self::ok(trim($raw));
    }

    /** @param  string[]  $allowedValues */
    private static function castSelect(mixed $raw, array $allowedValues): array
    {
        if (! is_string($raw)) {
            return self::fail('مقدار باید رشته باشد.');
        }
        if ($allowedValues !== [] && ! in_array($raw, $allowedValues, true)) {
            return self::fail('مقدار جزوِ گزینه‌هایِ مجازِ این فیلد نیست.');
        }

        return self::ok($raw);
    }

    private static function castReferenceId(mixed $raw): array
    {
        if (is_int($raw) && $raw > 0) {
            return self::ok($raw);
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return self::ok((int) $raw);
        }

        return self::fail('مقدار باید یک شناسهٔ عددیِ معتبر باشد.');
    }

    private static function ok(mixed $value): array
    {
        return ['ok' => true, 'value' => $value, 'error' => null];
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'value' => null, 'error' => $error];
    }
}
