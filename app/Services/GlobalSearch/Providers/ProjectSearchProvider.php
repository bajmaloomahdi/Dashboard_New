<?php

namespace App\Services\GlobalSearch\Providers;

use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\Contracts\SearchProvider;
use App\Services\GlobalSearch\PersianNormalizer;
use App\Services\GlobalSearch\SearchGroupResult;
use App\Services\GlobalSearch\SearchQuery;
use App\Services\GlobalSearch\SearchResultItem;
use App\Services\GlobalSearch\Support\SearchConnection;
use PDO;

/**
 * گروهِ پروژه‌ها — فقط کد، عنوان و وضعیت.
 *
 * دسترسی (§7.1) — همان قاعدهٔ صفحهٔ پروژه (ProjectsController::show ← sp_GetProjects @UserID):
 * سازندهٔ پروژه یا عضوِ فعال. عضویتِ غیرفعال به‌تنهایی دسترسی نمی‌دهد؛ دروازهٔ مجوز و
 * استثنایِ مدیر وجود ندارد. پروژهٔ غیرفعال برایِ همین کاربران با برچسبِ «غیرفعال» نمایش داده می‌شود.
 * قاعده کاملاً در dbo.sp_Gs_SearchProjects پیش از تطبیق اعمال می‌شود. هیچ دادهٔ CRM
 * (مالک)، اعضا، تعامل یا نامه‌ای برگردانده نمی‌شود.
 */
class ProjectSearchProvider implements SearchProvider
{
    protected string $procedure = 'dbo.sp_Gs_SearchProjects';

    private const INACTIVE_BADGE = 'غیرفعال';

    public function __construct(private readonly PersianNormalizer $normalizer)
    {
    }

    public function key(): string
    {
        return 'projects';
    }

    public function label(): string
    {
        return 'پروژه‌ها';
    }

    public function requiredPermission(): ?string
    {
        return null; // قاعدهٔ سطحِ رکورد (سازنده/عضوِ فعال)، نه مجوزِ نقش
    }

    public function search(SearchQuery $query, AccessContext $access, int $limit, int $timeoutSeconds): SearchGroupResult
    {
        $rows = $this->execute(
            "EXEC {$this->procedure} @UserID = ?, @Query = ?, @QueryLike = ?, "
            . '@T1 = ?, @T2 = ?, @T3 = ?, @T4 = ?, @T5 = ?, @T6 = ?, @Top = ?',
            $this->bindings($query, $access, $limit),
            $timeoutSeconds
        );

        $items = array_map(fn (array $r) => new SearchResultItem(
            type: 'project',
            id: (int) $r['ItemID'],
            title: (string) $r['Title'],
            subtitle: implode(' · ', array_filter([trim((string) ($r['ProjectCode'] ?? '')), trim((string) ($r['StatusTitle'] ?? ''))], fn ($p) => $p !== '')),
            url: '/projects/' . (int) $r['ItemID'],
            badges: ((int) $r['IsInactive']) === 1 ? [self::INACTIVE_BADGE] : [],
            match: $this->matchKind((int) $r['Score']),
        ), $rows);

        return new SearchGroupResult(
            $this->key(),
            $this->label(),
            $items,
            count($items) > $limit,
            '/projects', // بدونِ متنِ جست‌وجو در URL (یافتهٔ ۱ ممیزی)
        );
    }

    private function bindings(SearchQuery $query, AccessContext $access, int $limit): array
    {
        $tokens = array_slice($query->tokens, 0, 6);
        $params = [$access->userId, $query->normalized, $this->normalizer->escapeLike($query->normalized)];
        for ($i = 0; $i < 6; $i++) {
            $params[] = isset($tokens[$i]) ? $this->normalizer->escapeLike($tokens[$i]) : null;
        }
        $params[] = max(1, $limit);

        return $params;
    }

    /** @return array<int,array<string,mixed>> */
    protected function execute(string $sql, #[\SensitiveParameter] array $bindings, int $timeoutSeconds): array
    {
        // سهمِ این گروه از بودجهٔ مشترکِ درخواست (GlobalSearchService)؛ 0 در pdo_sqlsrv یعنی بی‌نهایت، پس حداقل ۱
        $timeout = max(1, $timeoutSeconds);
        $options = defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') ? [PDO::SQLSRV_ATTR_QUERY_TIMEOUT => $timeout] : [];

        // اتصالِ اختصاصیِ بدونِ Pooling؛ اتصالِ مجدد در صورتِ نیاز و ممنوعیتِ تراکنشِ باز (SearchConnection)
        $stmt = SearchConnection::pdo()->prepare(SearchConnection::guard($sql), $options);
        $stmt->execute($bindings);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function matchKind(int $score): string
    {
        return match (true) {
            $score >= 1000 => 'code',
            $score >= 500 => 'exact',
            $score >= 400 => 'prefix',
            $score >= 300 => 'word',
            $score >= 200 => 'contains',
            default => 'secondary',
        };
    }
}
