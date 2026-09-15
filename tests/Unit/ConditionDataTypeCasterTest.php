<?php

namespace Tests\Unit;

use App\Services\Workflow\Support\ConditionDataTypeCaster;
use App\Services\Workflow\Support\DecimalMath;
use Tests\TestCase;

/**
 * تستِ خالصِ منطقِ Cast/Compareِ Condition Engine — بدونِ هیچ وابستگیِ DB
 * (خودِ کلاس‌ها Static و بدونِ I/O هستند).
 */
class ConditionDataTypeCasterTest extends TestCase
{
    /* ==================== INTEGER — سرریز قبل از Cast ==================== */

    public function test_integer_accepts_php_int_max(): void
    {
        $result = ConditionDataTypeCaster::cast('INTEGER', (string) PHP_INT_MAX);
        $this->assertTrue($result['ok']);
        $this->assertSame(PHP_INT_MAX, $result['value']);
    }

    public function test_integer_rejects_php_int_max_plus_one(): void
    {
        $overflow = bcadd((string) PHP_INT_MAX, '1');
        $result = ConditionDataTypeCaster::cast('INTEGER', $overflow);
        $this->assertFalse($result['ok']);
    }

    public function test_integer_accepts_php_int_min(): void
    {
        $result = ConditionDataTypeCaster::cast('INTEGER', (string) PHP_INT_MIN);
        $this->assertTrue($result['ok']);
        $this->assertSame(PHP_INT_MIN, $result['value']);
    }

    public function test_integer_rejects_php_int_min_minus_one(): void
    {
        $underflow = bcsub((string) PHP_INT_MIN, '1');
        $result = ConditionDataTypeCaster::cast('INTEGER', $underflow);
        $this->assertFalse($result['ok']);
    }

    public function test_integer_rejects_decimal_looking_value(): void
    {
        $this->assertFalse(ConditionDataTypeCaster::cast('INTEGER', '5.0')['ok']);
        $this->assertFalse(ConditionDataTypeCaster::cast('INTEGER', 5.0)['ok']); // PHP float از json_decodeِ عددِ اعشاری
    }

    /* ==================== DECIMAL — بدونِ float، Locale-independent ==================== */

    public function test_decimal_rejects_json_number_only_accepts_json_string(): void
    {
        $this->assertFalse(ConditionDataTypeCaster::cast('DECIMAL', 100.50)['ok'], 'عددِ JSON (float) باید رد شود.');
        $this->assertFalse(ConditionDataTypeCaster::cast('DECIMAL', 100)['ok'], 'عددِ صحیحِ JSON (int) هم باید رد شود — DECIMAL فقط رشته می‌پذیرد.');

        $result = ConditionDataTypeCaster::cast('DECIMAL', '100.50');
        $this->assertTrue($result['ok'], 'رشتهٔ JSON معتبر باید پذیرفته شود.');
        $this->assertSame('100.5', $result['value']);
    }

    public function test_decimal_canonicalizes_trailing_and_leading_zeros(): void
    {
        $this->assertSame('0.1', ConditionDataTypeCaster::cast('DECIMAL', '0.10')['value']);
        $this->assertSame('100', ConditionDataTypeCaster::cast('DECIMAL', '100.00')['value']);
        $this->assertSame('7', ConditionDataTypeCaster::cast('DECIMAL', '007')['value']);
        $this->assertSame('0', ConditionDataTypeCaster::cast('DECIMAL', '-0.00')['value'], 'منفیِ صفر نباید وجود داشته باشد.');
    }

    public function test_decimal_accepts_negative_values(): void
    {
        $result = ConditionDataTypeCaster::cast('DECIMAL', '-1500.25');
        $this->assertTrue($result['ok']);
        $this->assertSame('-1500.25', $result['value']);
    }

    public function test_decimal_accepts_many_decimal_places(): void
    {
        $result = ConditionDataTypeCaster::cast('DECIMAL', '1.123456789012345');
        $this->assertTrue($result['ok']);
        $this->assertSame('1.123456789012345', $result['value']);
    }

    public function test_decimal_rejects_scientific_notation(): void
    {
        $this->assertFalse(ConditionDataTypeCaster::cast('DECIMAL', '1e5')['ok']);
        $this->assertFalse(ConditionDataTypeCaster::cast('DECIMAL', '1.5E+10')['ok']);
    }

    public function test_decimal_rejects_thousands_comma(): void
    {
        $this->assertFalse(ConditionDataTypeCaster::cast('DECIMAL', '1,500.25')['ok']);
        $this->assertFalse(ConditionDataTypeCaster::cast('DECIMAL', '150,5')['ok']); // جداکنندهٔ اعشاریِ اروپایی هم رد شود
    }

    public function test_decimal_rejects_persian_digits(): void
    {
        $this->assertFalse(ConditionDataTypeCaster::cast('DECIMAL', '۱۵۰۰.۲۵')['ok']);
    }

    /* ==================== DecimalMath — مقایسهٔ دقیق بدونِ float ==================== */

    public function test_decimal_math_treats_differently_formatted_equal_values_as_equal(): void
    {
        $this->assertSame(0, DecimalMath::compare('0.1', '0.10'));
        $this->assertSame(0, DecimalMath::compare('100.00', '100'));
        $this->assertSame(0, DecimalMath::compare('-0', '0'));
    }

    public function test_decimal_math_orders_correctly_avoiding_lexicographic_pitfall(): void
    {
        // مقایسهٔ رشته‌ایِ لغوی می‌گفت "9" > "10" — این باید غلط باشد.
        $this->assertSame(-1, DecimalMath::compare('9', '10'));
        $this->assertSame(1, DecimalMath::compare('10', '9'));
        $this->assertSame(-1, DecimalMath::compare('-5', '-3'));
        $this->assertSame(1, DecimalMath::compare('-3', '-5'));
    }

    public function test_decimal_math_handles_many_fractional_digits_without_float_rounding(): void
    {
        // اثباتِ Precisionِ IEEE-754: (0.1+0.2) در float برابرِ 0.3 نیست، ولی اینجا
        // اصلاً جمعی در کار نیست — فقط مقایسهٔ دو رشتهٔ Decimalِ مستقیم.
        $this->assertSame(0, DecimalMath::compare('0.30000000000000000001', '0.30000000000000000001'));
        $this->assertSame(1, DecimalMath::compare('0.30000000000000000002', '0.30000000000000000001'));
    }
}
