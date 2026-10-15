<?php

namespace App\Services\GlobalSearch;

/**
 * نتایجِ یک گروه. hasMore فقط بر اساسِ ردیف‌هایِ مجاز (پس از اعمالِ دسترسی) تعیین می‌شود.
 */
final class SearchGroupResult
{
    /** @param SearchResultItem[] $items */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $items,
        public readonly bool $hasMore = false,
        public readonly ?string $moreUrl = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'items' => array_map(fn (SearchResultItem $i) => $i->toArray(), array_values($this->items)),
            'hasMore' => $this->hasMore,
            'moreUrl' => $this->moreUrl,
        ];
    }
}
