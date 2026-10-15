<?php

namespace App\Services\GlobalSearch;

/**
 * یک نتیجه — فقط فیلدهایِ مجازِ §6 مشخصات (عنوان، زیرعنوان، مقصد، برچسب‌ها).
 * هیچ فیلدی از رکوردهایِ مرتبطی که قاعدهٔ دسترسیِ جداگانه دارند، در این شیء قرار نمی‌گیرد.
 */
final class SearchResultItem
{
    public const MATCH_KINDS = ['code', 'exact', 'prefix', 'word', 'contains', 'secondary'];

    /** @param string[] $badges */
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $url,
        public readonly array $badges = [],
        public readonly string $match = 'contains',
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'url' => $this->url,
            'badges' => array_values($this->badges),
            'match' => in_array($this->match, self::MATCH_KINDS, true) ? $this->match : 'contains',
        ];
    }
}
