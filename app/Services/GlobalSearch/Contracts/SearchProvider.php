<?php

namespace App\Services\GlobalSearch\Contracts;

use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\SearchGroupResult;
use App\Services\GlobalSearch\SearchQuery;

/**
 * ارائه‌دهندهٔ یک گروهِ جست‌وجو (CRM، نامه‌ها، پروژه‌ها، فرایندها).
 *
 * قرارداد:
 * - requiredPermission(): اگر کاربر این مجوز را نداشته باشد، search() اصلاً فراخوانی نمی‌شود
 *   و گروه در پاسخ نمی‌آید (§7.1 — «دروازهٔ مجوز»). null یعنی بدونِ دروازه (مثلاً نامه‌ها).
 * - search(): شرطِ دسترسیِ سطحِ رکورد را خودش اعمال می‌کند و حداکثر $limit + 1 ردیفِ مجاز
 *   می‌خواند تا hasMore درست باشد. فقط کوئریِ پارامتری؛ هیچ کشِ مشترک بینِ کاربران.
 * - $timeoutSeconds: سهمِ این ارائه‌دهنده از بودجهٔ زمانیِ مشترکِ کلِ درخواست (باقی‌ماندهٔ بودجه، گردشده
 *   به بالا، حداقل ۱ — کوچک‌ترین واحدِ Query Timeoutِ درایور یک ثانیه است). ارائه‌دهنده باید آن را
 *   به‌عنوانِ سقفِ اجرایِ کوئری اعمال کند؛ عبور از آن خطاست و فقط همین گروه حذف می‌شود.
 */
interface SearchProvider
{
    public function key(): string;

    public function label(): string;

    public function requiredPermission(): ?string;

    public function search(SearchQuery $query, AccessContext $access, int $limit, int $timeoutSeconds): SearchGroupResult;
}
