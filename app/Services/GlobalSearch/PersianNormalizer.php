<?php

namespace App\Services\GlobalSearch;

/**
 * یکسان‌سازیِ متنِ فارسی برایِ جست‌وجو — همتایِ دقیقِ resources/js/Utils/persianSearch.ts
 * (قواعدِ N1..N10 در §4 مشخصات؛ بردارهایِ آزمونِ مشترک در tests/Fixtures/global_search_normalization_vectors.json).
 *
 * نیم‌فاصله ← فاصله؛ آ/أ/إ/ٱ ← ا؛ «ئ» و «ؤ» بدونِ تغییر؛ ي/ى ← ی؛ ك ← ک؛
 * ارقامِ فارسی و عربی ← 0-9؛ حذفِ اعراب (U+064B..U+0652، U+0670)، کشیده و ZWJ/LRM/RLM؛
 * فاصله‌هایِ پشتِ‌سرِهم یکی، Trim، حروفِ لاتین کوچک. «ة» و «ۀ» فعلاً بدونِ تغییر.
 */
final class PersianNormalizer
{
    private const CHAR_MAP = [
        "\u{200C}" => ' ',      // نیم‌فاصله (ZWNJ)
        "\u{0622}" => "\u{0627}", // آ
        "\u{0623}" => "\u{0627}", // أ
        "\u{0625}" => "\u{0627}", // إ
        "\u{0671}" => "\u{0627}", // ٱ
        "\u{064A}" => "\u{06CC}", // ي
        "\u{0649}" => "\u{06CC}", // ى
        "\u{0643}" => "\u{06A9}", // ك
    ];

    /** اعراب (U+064B..U+0652، U+0670)، کشیده (U+0640)، ZWJ (U+200D)، LRM (U+200E)، RLM (U+200F) */
    private const REMOVED = '/^[\x{064B}-\x{0652}\x{0670}\x{0640}\x{200D}\x{200E}\x{200F}]$/u';

    /** همان مجموعهٔ \s در JavaScript (تا خروجیِ PHP و TS یکسان بماند) */
    private const WHITESPACE = '/^[\t\n\x{000B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]$/u';

    public function normalize(#[\SensitiveParameter] string $input): string
    {
        $out = '';
        $prevSpace = true; // فاصله‌هایِ ابتدایی حذف می‌شوند

        foreach (mb_str_split($input, 1, 'UTF-8') as $c) {
            if (preg_match(self::REMOVED, $c)) {
                continue;
            }

            $code = mb_ord($c, 'UTF-8');
            if ($code >= 0x06F0 && $code <= 0x06F9) {
                $c = chr($code - 0x06F0 + 48);
            } elseif ($code >= 0x0660 && $code <= 0x0669) {
                $c = chr($code - 0x0660 + 48);
            } else {
                $c = self::CHAR_MAP[$c] ?? $c;
            }

            if (preg_match(self::WHITESPACE, $c)) {
                if ($prevSpace) {
                    continue;
                }
                $out .= ' ';
                $prevSpace = true;
                continue;
            }

            $prevSpace = false;
            $out .= mb_strtolower($c, 'UTF-8');
        }

        return rtrim($out, ' ');
    }

    /** @return string[] */
    public function tokenize(#[\SensitiveParameter] string $normalized): array
    {
        return array_values(array_filter(explode(' ', $normalized), fn ($t) => $t !== ''));
    }

    /**
     * Escapeِ کاراکترهایِ خاصِ LIKE در SQL Server (% _ [) برایِ استفاده در الگو با پارامتر.
     * نتیجه همیشه به‌صورتِ پارامتر ارسال می‌شود، نه درونِ متنِ SQL.
     */
    public function escapeLike(#[\SensitiveParameter] string $value): string
    {
        return strtr($value, ['[' => '[[]', '%' => '[%]', '_' => '[_]']);
    }
}
