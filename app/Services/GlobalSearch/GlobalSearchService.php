<?php

namespace App\Services\GlobalSearch;

use App\Services\GlobalSearch\Contracts\SearchProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * هماهنگ‌کنندهٔ جست‌وجویِ سراسری (§2 مشخصات).
 * - ارائه‌دهنده‌ها به ترتیبِ config('global_search.providers') و فقط اگر روشن باشند اجرا می‌شوند.
 * - دروازهٔ مجوز پیش از هر فراخوانی؛ گروهِ بدونِ مجوز یا بدونِ نتیجه در پاسخ نمی‌آید.
 * - سقفِ نتایج و بودجهٔ زمانی؛ خطایِ یک ارائه‌دهنده فقط همان گروه را حذف می‌کند (complete = false).
 * - متنِ جست‌وجو هرگز در لاگ ثبت نمی‌شود.
 */
final class GlobalSearchService
{
    public function __construct(
        private readonly PersianNormalizer $normalizer,
        private readonly Container $container,
    ) {
    }

    public function buildQuery(#[\SensitiveParameter] string $raw): SearchQuery
    {
        $normalized = $this->normalizer->normalize($raw);

        return new SearchQuery($raw, $normalized, $this->normalizer->tokenize($normalized));
    }

    /** کلیدهایِ گروه‌هایِ تعریف‌شده (برایِ اعتبارسنجیِ پارامترِ groups) */
    public function knownGroupKeys(): array
    {
        return array_keys((array) config('global_search.providers', []));
    }

    /**
     * @param  string[]|null  $requestedGroups  null = همهٔ گروه‌ها
     * @return array{groups: array<int,array<string,mixed>>, complete: bool}
     */
    public function search(AccessContext $access, SearchQuery $query, ?array $requestedGroups = null): array
    {
        $perGroup = max(1, (int) config('global_search.limits.per_group', 5));
        $remaining = max(1, (int) config('global_search.limits.total', 30));
        $budgetMs = max(1, (int) config('global_search.limits.time_budget_ms', 1500));
        $startedAt = hrtime(true);

        $groups = [];
        $complete = true;

        foreach ((array) config('global_search.providers', []) as $key => $def) {
            if ($requestedGroups !== null && ! in_array($key, $requestedGroups, true)) {
                continue;
            }
            if (empty($def['enabled']) || empty($def['class'])) {
                continue;
            }
            if ($remaining <= 0) {
                break;
            }
            // بودجهٔ زمانی برایِ کلِ درخواست مشترک است (یافتهٔ ۴ ممیزی): هر ارائه‌دهنده فقط باقی‌ماندهٔ آن را می‌گیرد.
            // Query Timeoutِ pdo_sqlsrv عددِ صحیحِ ثانیه است و 0 یعنی بی‌نهایت؛ پس ceil و حداقل ۱ —
            // در بدترین حالت کلِ مرحلهٔ جست‌وجو کمتر از «بودجه + ۱ ثانیه» طول می‌کشد.
            $remainingMs = $budgetMs - (hrtime(true) - $startedAt) / 1e6;
            if ($remainingMs <= 0) {
                $complete = false;
                break;
            }
            $timeoutSeconds = max(1, (int) ceil($remainingMs / 1000));

            try {
                $provider = $this->container->make($def['class']);
                if (! $provider instanceof SearchProvider) {
                    Log::warning('global_search.provider_invalid', ['provider' => (string) $key]);
                    continue;
                }

                $permission = $provider->requiredPermission();
                if ($permission !== null && ! $access->can($permission)) {
                    continue; // §7.1: گروهِ بدونِ مجوز اصلاً در پاسخ نمی‌آید
                }

                $limit = min($perGroup, $remaining);
                $result = $provider->search($query, $access, $limit, $timeoutSeconds);

                $items = array_slice(array_values($result->items), 0, $limit);
                if ($items === []) {
                    continue;
                }

                $hasMore = $result->hasMore || count($result->items) > $limit;
                $groups[] = (new SearchGroupResult($result->key, $result->label, $items, $hasMore, $result->moreUrl))->toArray();
                $remaining -= count($items);
            } catch (\Throwable $e) {
                // بدونِ متنِ جست‌وجو و بدونِ جزئیاتِ SQL در پاسخ
                Log::warning('global_search.provider_failed', ['provider' => (string) $key, 'exception' => $e::class]);
                $complete = false;
            }
        }

        return ['groups' => $groups, 'complete' => $complete];
    }
}
