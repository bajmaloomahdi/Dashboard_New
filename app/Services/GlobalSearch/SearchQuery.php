<?php

namespace App\Services\GlobalSearch;

/**
 * پرس‌وجویِ نرمال‌شده — تنها ورودیِ ارائه‌دهنده‌ها.
 * متنِ خام فقط برایِ پاسخ نگه داشته می‌شود و هرگز در لاگ ثبت نمی‌شود.
 */
final class SearchQuery
{
    /** @param string[] $tokens */
    public function __construct(
        #[\SensitiveParameter] public readonly string $raw,
        #[\SensitiveParameter] public readonly string $normalized,
        #[\SensitiveParameter] public readonly array $tokens,
    ) {
    }
}
