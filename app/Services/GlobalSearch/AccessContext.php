<?php

namespace App\Services\GlobalSearch;

/**
 * وضعیتِ دسترسیِ کاربرِ جاری برایِ یک درخواستِ جست‌وجو (§7 مشخصات).
 * در هر درخواست تازه ساخته می‌شود؛ بینِ درخواست‌ها کش نمی‌شود.
 */
final class AccessContext
{
    /** @param string[] $permissions */
    public function __construct(
        public readonly int $userId,
        public readonly bool $isActive,
        private readonly array $permissions,
    ) {
    }

    public function can(string $permissionCode): bool
    {
        return in_array($permissionCode, $this->permissions, true);
    }
}
